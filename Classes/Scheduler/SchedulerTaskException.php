<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Scheduler;

/**
 * Why a scheduler task could not be created. Carries the label the backend
 * shows for it; the message itself is for the log.
 */
final class SchedulerTaskException extends \RuntimeException
{
    /**
     * @param list<string|int> $labelArguments
     */
    private function __construct(
        string $message,
        public readonly string $labelKey,
        public readonly array $labelArguments = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function schedulerMissing(): self
    {
        return new self('The scheduler extension is not installed.', 'scheduler.notInstalled');
    }

    public static function saveFailed(string $command, ?\Throwable $cause = null): self
    {
        $detail = $cause === null ? 'the repository refused it' : $cause->getMessage();

        return new self(
            sprintf('The task for %s could not be created: %s', $command, $detail),
            'scheduler.taskFailed',
            [$command, $detail],
            $cause
        );
    }
}
