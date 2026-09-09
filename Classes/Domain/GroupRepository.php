<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class GroupRepository
{
    public const TABLE = 'tx_caretaker2_group';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<int, array{uid: int, title: string, description: string}>
     */
    public function findAllIndexed(int $tenant = 1): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('uid', 'title', 'description')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)))
            ->orderBy('sorting')
            ->addOrderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int)$row['uid']] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'description' => (string)($row['description'] ?? ''),
            ];
        }

        return $groups;
    }
}
