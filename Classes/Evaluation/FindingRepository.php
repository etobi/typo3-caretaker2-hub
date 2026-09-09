<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FindingRepository
{
    public const TABLE = 'tx_caretaker2_finding';

    private const SEVERITY_ORDER = ['critical', 'high', 'unknown', 'medium', 'low', 'info'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Replaces the findings of one instance with a fresh set.
     *
     * Known findings keep their first_seen and their acknowledgement — a
     * finding that is merely re-detected must not pop back up as new, or
     * acknowledging anything would be pointless.
     *
     * @param list<Finding> $findings
     * @return array{added: int, kept: int, resolved: int}
     */
    public function replaceForInstance(int $instance, int $tenant, array $findings): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();

        $existing = [];
        foreach ($this->rowsForInstance($instance) as $row) {
            $existing[$row['finding_type'] . "\0" . $row['identifier']] = $row;
        }

        $added = 0;
        $kept = 0;

        foreach ($findings as $finding) {
            $key = $finding->type . "\0" . $finding->identifier;
            $row = $finding->toRow();
            $row['last_seen'] = $now;
            $row['tstamp'] = $now;

            if (isset($existing[$key])) {
                $previous = $existing[$key];

                // A finding that got worse comes back. Acknowledging "medium"
                // must not silently cover the same package turning critical.
                if ($this->isMoreSevere($finding->severity, (string)$previous['severity'])) {
                    $row['acknowledged'] = 0;
                    $row['ack_note'] = null;
                    $row['ack_user'] = '';
                    $row['ack_at'] = 0;
                }

                $connection->update(self::TABLE, $row, ['uid' => (int)$previous['uid']]);
                unset($existing[$key]);
                $kept++;
                continue;
            }

            $connection->insert(self::TABLE, array_merge($row, [
                'pid' => 0,
                'crdate' => $now,
                'instance' => $instance,
                'tenant' => $tenant,
                'first_seen' => $now,
            ]));
            $added++;
        }

        $stale = array_map(static fn(array $r): int => (int)$r['uid'], $existing);
        if ($stale !== []) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->delete(self::TABLE)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($stale, ArrayParameterType::INTEGER)))
                ->executeStatement();
        }

        return ['added' => $added, 'kept' => $kept, 'resolved' => count($stale)];
    }

    /**
     * @param list<int> $uids
     * @return int how many were actually acknowledged
     */
    public function acknowledgeMany(array $uids, int $instance, int $tenant, string $user, string $note): int
    {
        $uids = array_values(array_unique(array_filter($uids)));
        if ($uids === []) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $now = time();

        return (int)$qb
            ->update(self::TABLE)
            ->set('acknowledged', 1)
            ->set('ack_note', $note)
            ->set('ack_user', $user)
            ->set('ack_at', $now)
            ->set('tstamp', $now)
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($uids, ArrayParameterType::INTEGER)),
                // Scoped to the instance and tenant on purpose: the uids come
                // from a form and must not be able to reach another instance's
                // findings.
                $qb->expr()->eq('instance', $qb->createNamedParameter($instance, ParameterType::INTEGER)),
                $qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)),
            )
            ->executeStatement();
    }

    public function unacknowledge(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'acknowledged' => 0,
                'ack_note' => null,
                'ack_user' => '',
                'ack_at' => 0,
                'tstamp' => time(),
            ],
            ['uid' => $uid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid, int $tenant = 1): ?array
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

        return $row === false ? null : $row;
    }

    private function isMoreSevere(string $candidate, string $current): bool
    {
        $order = array_flip(self::SEVERITY_ORDER);

        return ($order[$candidate] ?? 99) < ($order[$current] ?? 99);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForInstance(int $instance, int $tenant = 1): array
    {
        $rows = $this->rowsForInstance($instance, $tenant);

        usort($rows, static function (array $a, array $b): int {
            $order = array_flip(self::SEVERITY_ORDER);
            $sa = $order[$a['severity']] ?? 99;
            $sb = $order[$b['severity']] ?? 99;

            return $sa <=> $sb ?: strcmp((string)$a['package'], (string)$b['package']);
        });

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function countsForInstance(int $instance, int $tenant = 1): array
    {
        $counts = ['security' => 0, 'update_safe' => 0, 'update_major' => 0, 'abandoned' => 0, 'unassessable' => 0, 'report' => 0];

        foreach ($this->rowsForInstance($instance, $tenant) as $row) {
            if ((int)$row['acknowledged'] === 1) {
                continue;
            }
            $type = (string)$row['finding_type'];
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }

        return $counts;
    }

    /**
     * Counts for many instances in one query — a list of a hundred instances
     * must not turn into a hundred round trips.
     *
     * @param list<int> $instanceIds
     * @return array<int, array<string, int>>
     */
    public function countsForInstances(array $instanceIds, int $tenant = 1): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('instance', 'finding_type', 'severity')
            ->addSelectLiteral($qb->expr()->count('uid', 'amount'))
            ->from(self::TABLE)
            ->where(
                $qb->expr()->in('instance', $qb->createNamedParameter($instanceIds, ArrayParameterType::INTEGER)),
                $qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)),
                $qb->expr()->eq('acknowledged', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->groupBy('instance', 'finding_type', 'severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $uid = (int)$row['instance'];
            $counts[$uid] ??= ['security' => 0, 'securityHigh' => 0, 'typo3Unsupported' => 0, 'phpUnsupported' => 0, 'update_safe' => 0, 'update_major' => 0, 'abandoned' => 0, 'unassessable' => 0, 'report' => 0];

            $type = (string)$row['finding_type'];
            $amount = (int)$row['amount'];

            if (isset($counts[$uid][$type])) {
                $counts[$uid][$type] += $amount;
            }
            // "unknown" counts as serious. A missing rating is not evidence of
            // harmlessness — and because the rating comes from the GitHub
            // Advisory Database, which lags the FriendsOfPHP feed by days, the
            // advisories without one are precisely the newest.
            if ($type === 'security' && in_array($row['severity'], ['critical', 'high', 'unknown'], true)) {
                $counts[$uid]['securityHigh'] += $amount;
            }

            // A TYPO3 version past its updates is not a package finding, but it
            // decides the state of the instance just as much.
            if ($type === Finding::TYPE_TYPO3_ELTS_UNPATCHED || $type === Finding::TYPE_TYPO3_UNSUPPORTED) {
                $counts[$uid]['typo3Unsupported'] += $amount;
            }

            if ($type === Finding::TYPE_PHP_EOL) {
                $counts[$uid]['phpUnsupported'] += $amount;
            }
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForInstance(int $instance, int $tenant = 1): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $qb
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('instance', $qb->createNamedParameter($instance, ParameterType::INTEGER)),
                $qb->expr()->eq('tenant', $qb->createNamedParameter($tenant, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
