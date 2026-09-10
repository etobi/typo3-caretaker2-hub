<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

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

    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly SnapshotRepository $snapshots,
        private readonly InventoryNormalizer $normalizer,
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

        $fingerprint = $this->normalizer->fingerprint($inventory);
        $changed = $fingerprint !== $instance->lastFingerprint;

        if ($changed) {
            $this->snapshots->add($instance, $fingerprint, $inventory);
        }

        $sites = $this->summaryFromSites($providers['sites'] ?? null);

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
            $sites,
        ));

        $this->reevaluateOtherClaimants($instance, $sites['site_hosts']);

        return ['stored' => $changed, 'fingerprint' => $fingerprint];
    }

    /**
     * A domain claimed by two instances is a finding on both. The other
     * side would only notice at its next scheduled evaluation, so it is
     * queued right away whenever this instance's domains change, including
     * the instances that shared a host it just gave up.
     */
    private function reevaluateOtherClaimants(Instance $instance, string $hostsNow): void
    {
        $hostsNow = array_values(array_filter(explode("\n", $hostsNow)));
        if ($hostsNow === $instance->siteHosts) {
            return;
        }

        $affected = $this->instances->findClaiming(
            array_values(array_unique(array_merge($hostsNow, $instance->siteHosts))),
            $instance->uid,
            $instance->tenant
        );

        $this->instances->markForEvaluation(
            array_map(static fn(Instance $other): int => $other->uid, $affected)
        );
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
}
