<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Nimmt ein Inventar entgegen, speichert es und zieht die Kennzahlen heraus,
 * die in der Instanzliste stehen.
 */
final class IngestService
{
    public const TABLE_SNAPSHOT = 'tx_caretaker2_snapshot';

    /**
     * Der Hub versteht die aktuelle Schemaversion und zwei davor.
     */
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_MIN_SUPPORTED = 1;

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
                'Schemaversion %d wird nicht mehr unterstützt, mindestens %d nötig. Agent aktualisieren.',
                $schemaVersion,
                self::SCHEMA_MIN_SUPPORTED
            ));
        }

        $providers = $inventory['providers'] ?? null;
        if (!is_array($providers)) {
            throw new IngestException('Inventar enthält keine Provider.');
        }

        $fingerprint = $this->fingerprint($inventory);
        $changed = $fingerprint !== $instance->lastFingerprint;

        // Nur bei Änderung ein neuer Snapshot. Die Update-Historie ergibt
        // sich dadurch als Diff-Kette, ohne dass wir sie extra führen — und
        // ein täglicher Push kostet an unveränderten Tagen nichts.
        if ($changed) {
            $this->connectionPool->getConnectionForTable(self::TABLE_SNAPSHOT)->insert(
                self::TABLE_SNAPSHOT,
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
                'schema_version' => $schemaVersion,
                'agent_version' => (string)($inventory['agent']['version'] ?? ''),
                'worst_provider_status' => $this->worstStatus($providers),
            ],
            $this->summaryFromCore($providers['core'] ?? null),
            $this->summaryFromPlatform($providers['platform'] ?? null),
        ));

        return ['stored' => $changed, 'fingerprint' => $fingerprint];
    }

    /**
     * Die Kennzahlen für die Instanzliste. Fehlt der core-Provider oder hat
     * er nichts geliefert, bleiben die Felder leer — und die Liste zeigt das
     * an, statt einen alten Wert weiterzuschleppen.
     *
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
     * PHP- und Datenbankversion für die Liste. Der Hub baut aus denselben
     * Rohwerten später den config.platform-Block für Composer — deshalb
     * meldet der Agent sie roh und bewertet nichts.
     *
     * @param mixed $platform
     * @return array<string, mixed>
     */
    private function summaryFromPlatform($platform): array
    {
        $empty = ['php_version' => '', 'db_platform' => '', 'db_version' => ''];

        // Auch ein degraded-Ergebnis trägt Daten — die nehmen wir mit, statt
        // die Instanz so aussehen zu lassen, als hätte sie gar nichts gemeldet.
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
     * Der schlechteste Zustand gewinnt. Ein einziger Provider, der nichts
     * liefern konnte, macht die ganze Instanz "unvollständig geprüft" —
     * denn niemand weiß, was in der Lücke gesteckt hätte.
     *
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
        unset($inventory['generatedAt']);

        return hash('sha256', (string)json_encode($inventory));
    }
}
