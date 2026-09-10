<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Snapshots are only written on change, so their order already is the
 * instance's history of changes, without it being kept separately.
 *
 * The payload is stored zlib-compressed. Almost all of it is composer.lock,
 * which shrinks to roughly a sixth; that is the difference between a few
 * hundred megabytes and a few gigabytes a year at a hundred instances.
 * Rows written before compression are still read, and caretaker2:cleanup
 * compresses them along the way.
 */
final class SnapshotRepository
{
    public const TABLE = 'tx_caretaker2_snapshot';

    private const COMPRESSION_LEVEL = 9;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $inventory as the agent sent it
     */
    public function add(Instance $instance, string $fingerprint, array $inventory): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'instance' => $instance->uid,
            'tenant' => $instance->tenant,
            'fingerprint' => $fingerprint,
            'payload' => $this->encode((string)json_encode($inventory, JSON_UNESCAPED_SLASHES)),
        ]);
    }

    /**
     * Compresses the snapshots that were written before compression.
     *
     * @return int how many
     */
    public function compressStoredPlain(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('uid', 'payload')
            ->from(self::TABLE)
            ->where($qb->expr()->comparison('LEFT(' . $qb->quoteIdentifier('payload') . ', 1)', '=', $qb->createNamedParameter('{')))
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $connection->update(
                self::TABLE,
                ['payload' => $this->encode((string)$row['payload'])],
                ['uid' => (int)$row['uid']]
            );
        }

        return count($rows);
    }

    /**
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

        $decoded = json_decode($this->decode((string)$row['payload']), true);

        return is_array($decoded)
            ? ['crdate' => (int)$row['crdate'], 'inventory' => $decoded]
            : null;
    }

    /**
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

    private function encode(string $json): string
    {
        return (string)gzcompress($json, self::COMPRESSION_LEVEL);
    }

    /**
     * A zlib stream starts with 0x78, JSON with a brace. That tells the two
     * generations of rows apart.
     */
    private function decode(string $payload): string
    {
        if ($payload === '' || $payload[0] !== "\x78") {
            return $payload;
        }

        $json = @gzuncompress($payload);

        return $json === false ? '' : $json;
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
