<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Scheduler;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;

final class HubTaskInstaller
{
    public const TASKS = [
        'caretaker2:evaluate' => '*/5 * * * *',
        'caretaker2:cleanup' => '17 3 * * *',
    ];

    private const TABLE = 'tx_scheduler_task';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('scheduler');
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        if (!$this->isAvailable()) {
            return array_keys(self::TASKS);
        }

        return array_values(array_filter(
            array_keys(self::TASKS),
            fn(string $command): bool => !$this->exists($command)
        ));
    }

    public function exists(string $command): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return (int)$qb
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('tasktype', $qb->createNamedParameter($command))
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @return int how many were created
     * @throws SchedulerTaskException
     */
    public function installMissing(): int
    {
        if (!$this->isAvailable()) {
            throw SchedulerTaskException::schedulerMissing();
        }

        $created = 0;
        foreach ($this->missing() as $command) {
            $this->install($command, self::TASKS[$command]);
            $created++;
        }

        return $created;
    }

    /**
     * @throws SchedulerTaskException
     */
    private function install(string $command, string $cron): void
    {
        $task = GeneralUtility::makeInstance(ExecuteSchedulableCommandTask::class);
        $task->setTaskType($command);
        $task->setTaskParameters(['commandIdentifier' => $command]);
        $task->setDescription('Caretaker2: ' . $command);
        $task->setExecution(Execution::createRecurringExecution(time(), 0, 0, false, $cron));

        try {
            $saved = GeneralUtility::makeInstance(SchedulerTaskRepository::class)->add($task);
        } catch (\Throwable $e) {
            throw SchedulerTaskException::saveFailed($command, $e);
        }

        if ($saved === false) {
            throw SchedulerTaskException::saveFailed($command);
        }
    }
}
