<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Eine überwachte Instanz, so wie der Hub sie kennt.
 *
 * Bewusst ein schlichtes Objekt über einer Doctrine-Tabelle statt eines
 * Extbase-Models: Diese Datensätze werden fast nur maschinell geschrieben.
 */
final readonly class Instance
{
    public function __construct(
        public int $uid,
        public int $tenant,
        public string $title,
        public int $groupUid,
        public string $instanceUrl,
        public string $agentVersion,
        public int $schemaVersion,
        public string $typo3Version,
        public int $typo3Major,
        public string $applicationContext,
        public string $phpVersion,
        public string $dbPlatform,
        public string $dbVersion,
        public string $worstProviderStatus,
        /** @var list<string> */
        public array $siteHosts,
        public int $siteCount,
        public int $lastSeen,
        public string $lastFingerprint,
        /** @var array<string, mixed>|null */
        public ?array $lastInventory,
        public bool $needsEvaluation,
        public int $evaluatedAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int)$row['uid'],
            tenant: (int)($row['tenant'] ?? 1),
            title: (string)($row['title'] ?? ''),
            groupUid: (int)($row['instance_group'] ?? 0),
            instanceUrl: (string)($row['instance_url'] ?? ''),
            agentVersion: (string)($row['agent_version'] ?? ''),
            schemaVersion: (int)($row['schema_version'] ?? 0),
            typo3Version: (string)($row['typo3_version'] ?? ''),
            typo3Major: (int)($row['typo3_major'] ?? 0),
            applicationContext: (string)($row['application_context'] ?? ''),
            phpVersion: (string)($row['php_version'] ?? ''),
            dbPlatform: (string)($row['db_platform'] ?? ''),
            dbVersion: (string)($row['db_version'] ?? ''),
            worstProviderStatus: (string)($row['worst_provider_status'] ?? ''),
            siteHosts: array_values(array_filter(
                preg_split('/\R/', (string)($row['site_hosts'] ?? '')) ?: []
            )),
            siteCount: (int)($row['site_count'] ?? 0),
            lastSeen: (int)($row['last_seen'] ?? 0),
            lastFingerprint: (string)($row['last_fingerprint'] ?? ''),
            lastInventory: self::decodeInventory($row['last_inventory'] ?? null),
            needsEvaluation: (bool)($row['needs_evaluation'] ?? false),
            evaluatedAt: (int)($row['evaluated_at'] ?? 0),
        );
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    private static function decodeInventory($raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Weil der Agent pusht, ist ausbleibender Push das Lebenszeichen.
     * Eine Instanz, die sich zwei Tage nicht gemeldet hat, ist auffällig,
     * ohne dass der Hub irgendetwas anpingen müsste.
     */
    public function isStale(int $now, int $toleranceSeconds = 172800): bool
    {
        return $this->lastSeen === 0 || ($now - $this->lastSeen) > $toleranceSeconds;
    }

    /**
     * Vier Zustände, nicht drei. "unvollständig geprüft" ist bewusst weder
     * grün noch rot: Wer nicht alles sehen konnte, darf keine Entwarnung
     * geben, hat aber auch nichts Schlimmes gefunden.
     */
    public function healthState(int $now): string
    {
        if ($this->isStale($now)) {
            return 'stale';
        }
        if ($this->worstProviderStatus === 'unavailable' || $this->worstProviderStatus === 'degraded') {
            return 'incomplete';
        }

        return 'ok';
    }
}
