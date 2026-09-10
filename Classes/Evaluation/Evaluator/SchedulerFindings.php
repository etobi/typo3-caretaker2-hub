<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

/**
 * Does maintenance run at all. A scheduler that stopped takes every
 * scheduled task with it, so that is one finding, not one per task. A task
 * that fails or is skipped while the scheduler runs is a finding of its own.
 */
final class SchedulerFindings implements EvaluatorInterface
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    /**
     * A daily cron job is the slowest common setup. Anything beyond that
     * has stopped.
     */
    private const STALE_AFTER_SECONDS = 26 * 3600;

    /**
     * How long a task may wait past its due time before that is a finding.
     * Hourly cron jobs and long-running neighbours are normal.
     */
    private const OVERDUE_GRACE_SECONDS = 2 * 3600;

    private const MAX_MESSAGE_LENGTH = 500;

    public function key(): string
    {
        return 'scheduler';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        $provider = $inventory['providers']['scheduler'] ?? null;
        if (!is_array($provider)) {
            return [];
        }

        $status = (string)($provider['status'] ?? 'unavailable');
        $data = is_array($provider['data'] ?? null) ? $provider['data'] : [];
        $findings = [];

        if ($status !== 'ok') {
            $findings[] = new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: Severity::INFO,
                identifier: 'scheduler-' . (string)($provider['reason'] ?? $status),
                package: 'typo3/cms-scheduler',
                installedVersion: '',
                latestVersion: '',
                title: self::LL . 'finding.title.scheduler.incomplete',
                link: '',
                titleArguments: [(string)($provider['message'] ?? $provider['reason'] ?? $status)],
            );
        }

        if ($data === []) {
            return $findings;
        }

        $now = $this->collectedAt($inventory);
        $tasks = $this->tasks($data);
        $runs = is_array($data['runs']['tasks'] ?? null) ? $data['runs']['tasks'] : [];
        $enabled = array_filter($tasks, static fn(array $task): bool => !$task['disabled']);

        $lastRun = $data['runs']['lastRun'] ?? null;
        $lastRunEnd = is_array($lastRun) ? (int)($lastRun['end'] ?? 0) : 0;
        $stale = $lastRunEnd === 0 || $now - $lastRunEnd > self::STALE_AFTER_SECONDS;

        // Without tasks nothing is missed, however long the scheduler has
        // been silent.
        if ($stale && $enabled !== []) {
            $findings[] = $lastRunEnd === 0
                ? new Finding(
                    type: Finding::TYPE_SCHEDULER_STALE,
                    severity: Severity::MEDIUM,
                    identifier: 'scheduler-never-ran',
                    package: 'typo3/cms-scheduler',
                    installedVersion: '',
                    latestVersion: '',
                    title: self::LL . 'finding.title.scheduler.neverRan',
                    link: 'https://docs.typo3.org/c/typo3/cms-scheduler/main/en-us/Installation/CronJob.html',
                    titleArguments: [(string)count($enabled)],
                )
                : new Finding(
                    type: Finding::TYPE_SCHEDULER_STALE,
                    severity: Severity::MEDIUM,
                    identifier: 'scheduler-stale',
                    package: 'typo3/cms-scheduler',
                    installedVersion: '',
                    latestVersion: '',
                    title: self::LL . 'finding.title.scheduler.stale',
                    link: 'https://docs.typo3.org/c/typo3/cms-scheduler/main/en-us/Installation/CronJob.html',
                    titleArguments: [Finding::date($lastRunEnd)],
                );
        }

        foreach ($enabled as $task) {
            $label = $task['description'] !== '' ? $task['description'] : $task['type'];

            if ($task['lastFailure'] !== '') {
                $findings[] = new Finding(
                    type: Finding::TYPE_SCHEDULER_TASK_FAILED,
                    severity: Severity::MEDIUM,
                    identifier: 'scheduler-task-' . $task['uid'] . '-failed',
                    package: $task['type'],
                    installedVersion: '',
                    latestVersion: '',
                    title: self::LL . 'finding.title.scheduler.taskFailed',
                    link: '',
                    titleArguments: [$label, $task['lastFailure']],
                );
            }

            // While the scheduler is down every task is overdue, and that
            // is already said above.
            if ($stale) {
                continue;
            }

            $next = (int)($runs[(string)$task['uid']]['next'] ?? 0);
            if ($next > 0 && $now - $next > self::OVERDUE_GRACE_SECONDS) {
                $findings[] = new Finding(
                    type: Finding::TYPE_SCHEDULER_TASK_OVERDUE,
                    severity: Severity::LOW,
                    identifier: 'scheduler-task-' . $task['uid'] . '-overdue',
                    package: $task['type'],
                    installedVersion: '',
                    latestVersion: '',
                    title: self::LL . 'finding.title.scheduler.taskOverdue',
                    link: '',
                    titleArguments: [$label, Finding::date($next)],
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{uid: int, type: string, description: string, disabled: bool, lastFailure: string}>
     */
    private function tasks(array $data): array
    {
        $tasks = [];
        foreach ($data['tasks'] ?? [] as $task) {
            if (!is_array($task)) {
                continue;
            }

            $failure = trim((string)($task['lastFailure'] ?? ''));
            if (mb_strlen($failure) > self::MAX_MESSAGE_LENGTH) {
                $failure = mb_substr($failure, 0, self::MAX_MESSAGE_LENGTH) . '…';
            }

            $tasks[] = [
                'uid' => (int)($task['uid'] ?? 0),
                'type' => (string)($task['type'] ?? ''),
                'description' => trim((string)($task['description'] ?? '')),
                'disabled' => (bool)($task['disabled'] ?? false),
                'lastFailure' => $failure,
            ];
        }

        return $tasks;
    }

    /**
     * "Overdue" is measured from when the agent looked, not from when the
     * hub gets around to judging it.
     *
     * @param array<string, mixed> $inventory
     */
    private function collectedAt(array $inventory): int
    {
        $value = $inventory['generatedAt'] ?? null;
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Exception $e) {
            }
        }

        return time();
    }
}
