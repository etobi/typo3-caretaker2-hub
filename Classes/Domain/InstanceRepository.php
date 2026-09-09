<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class InstanceRepository
{
    public const TABLE = 'tx_caretaker2_instance';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<Instance>
     */
    public function findAll(int $tenant = 1): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            // The tenant condition lives here and not at the call site. An
            // optional parameter one can forget is exactly the gap that turns
            // up later as a data leak.
            ->where($qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): Instance => Instance::fromRow($row), $rows);
    }

    /**
     * Finds the instance for a token. The hash is what is compared; the token
     * itself is nowhere in the database.
     */
    public function findByToken(string $token): ?Instance
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq(
                'token_hash',
                $qb->createNamedParameter(TokenGenerator::hash($token))
            ))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Instance::fromRow($row);
    }

    /**
     * The tenant condition is here too, not only in findAll() — otherwise a
     * guessed uid would be the way around it.
     */
    public function findByUid(int $uid, int $tenant = 1): ?Instance
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                $qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Instance::fromRow($row);
    }

    /**
     * Instances waiting for an evaluation: either their inventory changed, or
     * their last evaluation is old enough that a newly published advisory
     * could have appeared since. An untouched instance can become vulnerable
     * overnight without sending anything.
     *
     * @return list<Instance>
     */
    public function findPendingEvaluation(int $maxAgeSeconds, int $limit, int $tenant = 1): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)),
                $qb->expr()->gt('last_seen', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->or(
                    $qb->expr()->eq('needs_evaluation', $qb->createNamedParameter(1, ParameterType::INTEGER)),
                    $qb->expr()->lt(
                        'evaluated_at',
                        $qb->createNamedParameter(time() - $maxAgeSeconds, ParameterType::INTEGER)
                    ),
                ),
            )
            ->orderBy('evaluated_at', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): Instance => Instance::fromRow($row), $rows);
    }

    public function create(int $tenant, string $title, string $instanceUrl, string $tokenHash): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'tenant' => $tenant,
            'title' => $title,
            'instance_url' => $instanceUrl,
            'token_hash' => $tokenHash,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $uid, array $values): void
    {
        $values['tstamp'] = time();
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $values, ['uid' => $uid], [Connection::PARAM_INT]);
    }
}
