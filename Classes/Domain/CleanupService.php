<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Caretaker2\Hub\Evaluation\FindingRepository;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CleanupService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly InstanceRepository $instances,
    ) {}

    /**
     * @return array{snapshots: int, findings: int}
     */
    public function forgetInstance(int $instanceUid): array
    {
        return [
            'snapshots' => $this->deleteBy(SnapshotRepository::TABLE, 'instance', $instanceUid),
            'findings' => $this->deleteBy(FindingRepository::TABLE, 'instance', $instanceUid),
        ];
    }

    /**
     * @return array{snapshots: int, findings: int}
     */
    public function resetInstance(int $instanceUid): array
    {
        $counts = $this->forgetInstance($instanceUid);
        $this->instances->clearReported($instanceUid);

        return $counts;
    }

    public function detachGroup(int $groupUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(InstanceRepository::TABLE);

        return (int)$qb
            ->update(InstanceRepository::TABLE)
            ->set('instance_group', 0)
            ->where($qb->expr()->eq(
                'instance_group',
                $qb->createNamedParameter($groupUid, ParameterType::INTEGER)
            ))
            ->executeStatement();
    }

    /**
     * @return array{snapshots: int, findings: int, groups: int}
     */
    public function removeOrphans(): array
    {
        $instanceUids = $this->existingUids(InstanceRepository::TABLE);
        $groupUids = $this->existingUids(GroupRepository::TABLE);

        return [
            'snapshots' => $this->deleteNotIn(SnapshotRepository::TABLE, 'instance', $instanceUids),
            'findings' => $this->deleteNotIn(FindingRepository::TABLE, 'instance', $instanceUids),
            'groups' => $this->detachMissingGroups($groupUids),
        ];
    }

    private function deleteBy(string $table, string $column, int $value): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);

        return (int)$qb
            ->delete($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, ParameterType::INTEGER)))
            ->executeStatement();
    }

    /**
     * @param list<int> $keep
     */
    private function deleteNotIn(string $table, string $column, array $keep): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->delete($table);

        if ($keep === []) {
            $qb->where($qb->expr()->gt($column, $qb->createNamedParameter(0, ParameterType::INTEGER)));
        } else {
            $qb->where($qb->expr()->notIn(
                $column,
                $qb->createNamedParameter($keep, \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ));
        }

        return (int)$qb->executeStatement();
    }

    /**
     * @param list<int> $existing
     */
    private function detachMissingGroups(array $existing): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(InstanceRepository::TABLE);
        $qb->update(InstanceRepository::TABLE)->set('instance_group', 0);

        $qb->where($qb->expr()->gt('instance_group', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        if ($existing !== []) {
            $qb->andWhere($qb->expr()->notIn(
                'instance_group',
                $qb->createNamedParameter($existing, \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ));
        }

        return (int)$qb->executeStatement();
    }

    /**
     * @return list<int>
     */
    private function existingUids(string $table): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $qb->select('uid')->from($table)->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)$row['uid'], $rows);
    }
}
