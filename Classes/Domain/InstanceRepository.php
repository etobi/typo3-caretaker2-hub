<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
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
            ->where($qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): Instance => Instance::fromRow($row), $rows);
    }

    /**
     * Finds the instance for a token.
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

    public function create(
        int $tenant,
        string $title,
        string $instanceUrl,
        string $tokenHash,
        string $agentVersion
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'tenant' => $tenant,
            'title' => $title,
            'instance_url' => $instanceUrl,
            'token_hash' => $tokenHash,
            'agent_version' => $agentVersion,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Forgets everything the agent ever reported. The record itself, its
     * title, group and token stay.
     */
    public function clearReported(int $uid): void
    {
        $this->update($uid, [
            'agent_version' => '',
            'schema_version' => 0,
            'typo3_version' => '',
            'typo3_major' => 0,
            'application_context' => '',
            'php_version' => '',
            'db_platform' => '',
            'db_version' => '',
            'worst_provider_status' => '',
            'site_hosts' => '',
            'site_count' => 0,
            'last_seen' => 0,
            'last_fingerprint' => '',
            'last_inventory' => '',
            'needs_evaluation' => 0,
            'evaluated_at' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $uid, array $values): void
    {
        $values['tstamp'] = time();
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $values, ['uid' => $uid]);
    }
}
