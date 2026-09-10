<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Support status of the PHP release branches, from endoflife.date.
 * @see Typo3MajorVersions
 */
final class PhpVersions implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const ENDPOINT = 'https://endoflife.date/api/php.json';
    private const CACHE_KEY = 'php-versions-v2';
    private const TIMEOUT_SECONDS = 15;

    /**
     * Asked for several times per instance in the list. Once per request is
     * enough.
     *
     * @var array<string, array{status: PhpSupport, cycle: string, supportUntil: ?int, eolUntil: ?int, latest: string}>|null
     */
    private ?array $loaded = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly FrontendInterface $cache,
    ) {}

    /**
     * @return array{status: PhpSupport, cycle: string, supportUntil: ?int, eolUntil: ?int, latest: string}
     */
    public function statusOf(string $version): array
    {
        $unknown = [
            'status' => PhpSupport::UNKNOWN,
            'cycle' => '',
            'supportUntil' => null,
            'eolUntil' => null,
            'latest' => '',
        ];

        if (preg_match('/^(\d+\.\d+)/', $version, $m) !== 1) {
            return $unknown;
        }

        $cycles = $this->load();

        return $cycles[$m[1]] ?? $unknown;
    }

    /**
     * @return array<string, array{status: PhpSupport, cycle: string, supportUntil: ?int, eolUntil: ?int, latest: string}>
     */
    public function load(): array
    {
        return $this->loaded ??= $this->loadFromCacheOrApi();
    }

    /**
     * @return array<string, array{status: PhpSupport, cycle: string, supportUntil: ?int, eolUntil: ?int, latest: string}>
     */
    private function loadFromCacheOrApi(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->fetch();
        if ($raw === null) {
            return [];
        }

        $cycles = $this->classify($raw);
        $this->cache->set(self::CACHE_KEY, $cycles, [], 86400);

        return $cycles;
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
            $this->logger?->warning('endoflife.date is unreachable', ['exception' => $e]);

            return null;
        }

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return array<string, array{status: PhpSupport, cycle: string, supportUntil: ?int, eolUntil: ?int, latest: string}>
     */
    private function classify(array $raw): array
    {
        $now = time();
        $cycles = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['cycle'])) {
                continue;
            }

            $cycle = (string)$entry['cycle'];
            $released = $this->timestamp($entry['releaseDate'] ?? null);
            $support = $this->timestamp($entry['support'] ?? null);
            $eol = $this->timestamp($entry['eol'] ?? null);

            if ($released === null || $released > $now) {
                continue;
            }

            if ($support !== null && $support >= $now) {
                $status = PhpSupport::ACTIVE;
            } elseif ($eol !== null && $eol >= $now) {
                $status = PhpSupport::SECURITY;
            } else {
                $status = PhpSupport::EOL;
            }

            $cycles[$cycle] = [
                'status' => $status,
                'cycle' => $cycle,
                'supportUntil' => $support,
                'eolUntil' => $eol,
                'latest' => (string)($entry['latest'] ?? ''),
            ];
        }

        return $cycles;
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
