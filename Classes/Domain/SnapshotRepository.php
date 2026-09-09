<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Snapshots werden nur bei Änderung geschrieben. Ihre Reihenfolge ist damit
 * bereits die Veränderungshistorie der Instanz — ohne dass wir sie separat
 * führen müssten.
 */
final class SnapshotRepository
{
    public const TABLE = 'tx_caretaker2_snapshot';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null Das Inventar, wie es der Agent geschickt hat
     */
    public function findLatestInventory(int $instanceId): ?array
    {
        $row = $this->query($instanceId)
            ->select('payload')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string)$row['payload'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Ein bestimmter Snapshot. Auf die Instanz eingegrenzt, damit eine
     * geratene uid nicht in fremde Daten führt.
     *
     * @return array{crdate: int, inventory: array<string, mixed>}|null
     */
    public function findInventoryByUid(int $snapshotUid, int $instanceId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->select('crdate', 'payload')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($snapshotUid, ParameterType::INTEGER)),
                $qb->expr()->eq('instance', $qb->createNamedParameter($instanceId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string)$row['payload'], true);

        return is_array($decoded)
            ? ['crdate' => (int)$row['crdate'], 'inventory' => $decoded]
            : null;
    }

    /**
     * Nur die Metadaten — die Payloads wären für eine Liste zu groß.
     *
     * @return list<array{uid: int, crdate: int, fingerprint: string}>
     */
    public function findHistory(int $instanceId, int $limit = 50): array
    {
        $rows = $this->query($instanceId)
            ->select('uid', 'crdate', 'fingerprint')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $r): array => [
                'uid' => (int)$r['uid'],
                'crdate' => (int)$r['crdate'],
                'fingerprint' => (string)$r['fingerprint'],
            ],
            $rows
        );
    }

    public function countForInstance(int $instanceId): int
    {
        return (int)$this->query($instanceId)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    private function query(int $instanceId): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $qb
            ->from(self::TABLE)
            ->where($qb->expr()->eq('instance', $qb->createNamedParameter($instanceId, ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC');
    }
}
