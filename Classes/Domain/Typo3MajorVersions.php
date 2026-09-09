<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Support status of the TYPO3 major versions, from get.typo3.org.
 *
 * The dates are not ours to keep: they move when the TYPO3 project moves them,
 * and a hardcoded table would quietly go wrong. Cached for a day, because they
 * change a few times a year at most.
 */
final class Typo3MajorVersions implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const STATUS_STABLE = 'stable';
    public const STATUS_OLDSTABLE = 'oldstable';
    public const STATUS_ELTS = 'elts';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_UNKNOWN = 'unknown';

    private const ENDPOINT = 'https://get.typo3.org/api/v1/major/';
    private const CACHE_KEY = 'typo3-major-versions';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly FrontendInterface $cache,
    ) {}

    /**
     * @return array{status: string, maintainedUntil: ?int, eltsUntil: ?int, title: string}
     */
    public function statusOf(int $major): array
    {
        $versions = $this->load();
        $unknown = [
            'status' => self::STATUS_UNKNOWN,
            'maintainedUntil' => null,
            'eltsUntil' => null,
            'title' => '',
        ];

        if ($versions === [] || !isset($versions[$major])) {
            return $unknown;
        }

        return $versions[$major];
    }

    /**
     * @return array<int, array{status: string, maintainedUntil: ?int, eltsUntil: ?int, title: string}>
     */
    public function load(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->fetch();
        if ($raw === null) {
            // Nothing cached: better to say "unknown" than to invent a status.
            return [];
        }

        $versions = $this->classify($raw);
        $this->cache->set(self::CACHE_KEY, $versions, [], 86400);

        return $versions;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetch(): ?array
    {
        try {
            $response = $this->requestFactory->request(self::ENDPOINT, 'GET', [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('get.typo3.org nicht erreichbar', ['exception' => $e]);

            return null;
        }

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return array<int, array{status: string, maintainedUntil: ?int, eltsUntil: ?int, title: string}>
     */
    private function classify(array $raw): array
    {
        $now = time();
        $entries = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['version'])) {
                continue;
            }

            $major = (int)$entry['version'];
            $released = $this->timestamp($entry['release_date'] ?? null);
            $maintained = $this->timestamp($entry['maintained_until'] ?? null);
            $elts = $this->timestamp($entry['elts_until'] ?? null);

            // Not out yet: no status to give, and no finding to raise.
            if ($released === null || $released > $now) {
                continue;
            }

            if ($maintained !== null && $maintained >= $now) {
                $status = self::STATUS_OLDSTABLE;
            } elseif ($elts !== null && $elts >= $now) {
                $status = self::STATUS_ELTS;
            } else {
                $status = self::STATUS_UNSUPPORTED;
            }

            $entries[$major] = [
                'status' => $status,
                'maintainedUntil' => $maintained,
                'eltsUntil' => $elts,
                'title' => (string)($entry['title'] ?? ''),
            ];
        }

        // The newest one still in regular maintenance is the current stable;
        // the others in maintenance are old stable.
        $inMaintenance = array_keys(array_filter(
            $entries,
            static fn(array $e): bool => $e['status'] === self::STATUS_OLDSTABLE
        ));

        if ($inMaintenance !== []) {
            $entries[max($inMaintenance)]['status'] = self::STATUS_STABLE;
        }

        return $entries;
    }

    /**
     * @param mixed $value
     */
    private function timestamp($value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
}
