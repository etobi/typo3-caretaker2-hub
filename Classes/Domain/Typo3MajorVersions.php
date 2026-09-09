<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Support status of the TYPO3 major versions, from get.typo3.org.
 */
final class Typo3MajorVersions implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const STATUS_STABLE = 'stable';
    public const STATUS_OLDSTABLE = 'oldstable';
    public const STATUS_ELTS = 'elts';
    public const STATUS_ELTS_UNPATCHED = 'elts_unpatched';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_UNKNOWN = 'unknown';

    private const ENDPOINT_MAJORS = 'https://get.typo3.org/api/v1/major/';
    private const ENDPOINT_RELEASES = 'https://get.typo3.org/api/v1/release/';
    private const CACHE_KEY = 'typo3-major-versions-v2';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly FrontendInterface $cache,
    ) {}

    /**
     * @return array{status: string, maintainedUntil: ?int, eltsUntil: ?int, title: string, lastPublic: string, latest: string}
     */
    public function statusOf(string $version): array
    {
        $unknown = [
            'status' => self::STATUS_UNKNOWN,
            'maintainedUntil' => null,
            'eltsUntil' => null,
            'title' => '',
            'lastPublic' => '',
            'latest' => '',
        ];

        $major = (int)$version;
        $versions = $this->load();

        if ($major <= 0 || $versions === [] || !isset($versions[$major])) {
            return $unknown;
        }

        $entry = $versions[$major];

        // Inside the ELTS window the patch level decides: past the last public
        // release means ELTS is actually being applied, at or below it means
        // the installation gets nothing.
        if ($entry['status'] === self::STATUS_ELTS
            && $entry['lastPublic'] !== ''
            && substr_count($version, '.') >= 2
            && version_compare($version, $entry['lastPublic'], '<=')
        ) {
            $entry['status'] = self::STATUS_ELTS_UNPATCHED;
        }

        return $entry;
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

        $majors = $this->fetch(self::ENDPOINT_MAJORS);
        $releases = $this->fetch(self::ENDPOINT_RELEASES);
        if ($majors === null || $releases === null) {
            // Nothing cached: better to say "unknown"
            return [];
        }

        $versions = $this->classify($majors, $this->boundaries($releases));
        $this->cache->set(self::CACHE_KEY, $versions, [], 86400);

        return $versions;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetch(string $endpoint): ?array
    {
        try {
            $response = $this->requestFactory->request($endpoint, 'GET', [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('get.typo3.org is unreachable', ['exception' => $e]);

            return null;
        }

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The last public and the newest release of each major.
     *
     * @param list<array<string, mixed>> $releases
     * @return array<int, array{lastPublic: string, latest: string}>
     */
    private function boundaries(array $releases): array
    {
        $out = [];

        foreach ($releases as $release) {
            if (!is_array($release) || !is_string($release['version'] ?? null)) {
                continue;
            }

            $version = $release['version'];
            $major = (int)$version;
            $out[$major] ??= ['lastPublic' => '', 'latest' => ''];

            if ($out[$major]['latest'] === '' || version_compare($version, $out[$major]['latest'], '>')) {
                $out[$major]['latest'] = $version;
            }

            if (($release['elts'] ?? false) === true) {
                continue;
            }

            if ($out[$major]['lastPublic'] === '' || version_compare($version, $out[$major]['lastPublic'], '>')) {
                $out[$major]['lastPublic'] = $version;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @param array<int, array{lastPublic: string, latest: string}> $boundaries
     * @return array<int, array{status: string, maintainedUntil: ?int, eltsUntil: ?int, title: string, lastPublic: string, latest: string}>
     */
    private function classify(array $raw, array $boundaries): array
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
                'lastPublic' => $boundaries[$major]['lastPublic'] ?? '',
                'latest' => $boundaries[$major]['latest'] ?? '',
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
