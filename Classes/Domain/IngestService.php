<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Takes an inventory in, stores it and pulls out the figures the instance
 * list shows.
 */
final class IngestService
{
    /**
     * The oldest inventory format the hub still understands. Rises only when
     * a format change cannot be read across any more.
     */
    public const SCHEMA_MIN_SUPPORTED = 1;

    /**
     * Values that depend on which PHP runtime collected the inventory rather
     * than on the instance itself. A scheduler push runs under CLI, a
     * hub-triggered pull under FPM, and they disagree about all of these.
     * Left in, every alternation between the two would look like a change.
     *
     * The data still reaches the hub and is shown — it just does not decide
     * whether a snapshot is stored.
     */
    private const VOLATILE_PATHS = [
        ['providers', 'platform', 'data', 'php', 'sapi'],
        ['providers', 'platform', 'data', 'php', 'settings'],
        // TYPO3's own checks judge the runtime they happen to run in, so a
        // scheduler push and a hub-triggered pull disagree about two of them.
        // They describe the current state, not a change to the installation,
        // and are always available in full from last_inventory.
        ['providers', 'reports'],
    ];

    private const SAPI_BOUND_EXTENSIONS = [
        'ext-pcntl',
        'ext-posix',
        'ext-readline',
        'ext-cgi-fcgi',
        'ext-litespeed',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly InstanceRepository $instances,
    ) {}

    /**
     * @param array<string, mixed> $inventory
     * @return array{stored: bool, fingerprint: string}
     * @throws IngestException
     */
    public function accept(Instance $instance, array $inventory): array
    {
        $schemaVersion = (int)($inventory['schemaVersion'] ?? 0);
        if ($schemaVersion < self::SCHEMA_MIN_SUPPORTED) {
            throw new IngestException(sprintf(
                'Schema version %d is no longer supported, %d is the minimum. Please update the agent.',
                $schemaVersion,
                self::SCHEMA_MIN_SUPPORTED
            ));
        }

        $providers = $inventory['providers'] ?? null;
        if (!is_array($providers)) {
            throw new IngestException('The inventory carries no providers.');
        }

        $fingerprint = $this->fingerprint($inventory);
        $changed = $fingerprint !== $instance->lastFingerprint;

        if ($changed) {
            $this->connectionPool->getConnectionForTable(SnapshotRepository::TABLE)->insert(
                SnapshotRepository::TABLE,
                [
                    'pid' => 0,
                    'crdate' => time(),
                    'instance' => $instance->uid,
                    'tenant' => $instance->tenant,
                    'fingerprint' => $fingerprint,
                    'payload' => (string)json_encode($inventory, JSON_UNESCAPED_SLASHES),
                ]
            );
        }

        $this->instances->update($instance->uid, array_merge(
            [
                'last_seen' => time(),
                'last_fingerprint' => $fingerprint,
                'last_inventory' => (string)json_encode($inventory, JSON_UNESCAPED_SLASHES),
                'schema_version' => $schemaVersion,
                'agent_version' => (string)($inventory['agent']['version'] ?? ''),
                'worst_provider_status' => $this->worstStatus($providers),
                'needs_evaluation' => $changed ? 1 : (int)$instance->needsEvaluation,
            ],
            $this->summaryFromCore($providers['core'] ?? null),
            $this->summaryFromPlatform($providers['platform'] ?? null),
            $this->summaryFromSites($providers['sites'] ?? null),
        ));

        return ['stored' => $changed, 'fingerprint' => $fingerprint];
    }

    /**
     * @param mixed $core
     * @return array<string, mixed>
     */
    private function summaryFromCore($core): array
    {
        $empty = [
            'typo3_version' => '',
            'typo3_major' => 0,
            'application_context' => '',
        ];

        if (!is_array($core) || ($core['status'] ?? null) !== 'ok' || !is_array($core['data'] ?? null)) {
            return $empty;
        }

        $data = $core['data'];

        return [
            'typo3_version' => (string)($data['version'] ?? ''),
            'typo3_major' => (int)($data['majorVersion'] ?? 0),
            'application_context' => (string)($data['applicationContext'] ?? ''),
        ];
    }

    /**
     * @param mixed $platform
     * @return array<string, mixed>
     */
    private function summaryFromPlatform($platform): array
    {
        $empty = ['php_version' => '', 'db_platform' => '', 'db_version' => ''];

        // A degraded result still carries data. Taking it beats letting the
        // instance look as though it had reported nothing at all.
        if (!is_array($platform)
            || !in_array($platform['status'] ?? null, ['ok', 'degraded'], true)
            || !is_array($platform['data'] ?? null)
        ) {
            return $empty;
        }

        $data = $platform['data'];

        return [
            'php_version' => (string)($data['php']['version'] ?? ''),
            'db_platform' => (string)($data['database']['platform'] ?? ''),
            'db_version' => (string)($data['database']['serverVersion'] ?? ''),
        ];
    }

    /**
     * @param mixed $sites
     * @return array<string, mixed>
     */
    private function summaryFromSites($sites): array
    {
        $empty = ['site_hosts' => '', 'site_count' => 0];

        if (!is_array($sites)
            || !in_array($sites['status'] ?? null, ['ok', 'degraded'], true)
            || !is_array($sites['data'] ?? null)
        ) {
            return $empty;
        }

        $hosts = $sites['data']['hosts'] ?? [];

        return [
            'site_hosts' => is_array($hosts) ? implode("\n", $hosts) : '',
            'site_count' => (int)($sites['data']['count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $providers
     */
    private function worstStatus(array $providers): string
    {
        $worst = 'ok';

        foreach ($providers as $provider) {
            $status = is_array($provider) ? ($provider['status'] ?? 'unavailable') : 'unavailable';
            if ($status === 'unavailable') {
                return 'unavailable';
            }
            if ($status === 'degraded') {
                $worst = 'degraded';
            }
        }

        return $worst;
    }

    /**
     * @param array<string, mixed> $inventory
     */
    private function fingerprint(array $inventory): string
    {
        return hash('sha256', (string)json_encode($this->normalize($inventory)));
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private function normalize(array $inventory): array
    {
        unset($inventory['generatedAt']);

        foreach (self::VOLATILE_PATHS as $path) {
            $inventory = $this->forget($inventory, $path);
        }

        $extensions = &$inventory['providers']['platform']['data']['php']['extensions'];
        if (is_array($extensions)) {
            foreach (self::SAPI_BOUND_EXTENSIONS as $name) {
                unset($extensions[$name]);
            }
        }
        unset($extensions);

        return $inventory;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function forget(array $data, array $path): array
    {
        $key = array_shift($path);

        if (!array_key_exists($key, $data)) {
            return $data;
        }

        if ($path === []) {
            unset($data[$key]);

            return $data;
        }

        if (is_array($data[$key])) {
            $data[$key] = $this->forget($data[$key], $path);
        }

        return $data;
    }
}
