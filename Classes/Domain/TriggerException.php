<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Why an instance could not be asked to report. Carries the label the
 * backend shows for it; the message itself is for the log.
 */
final class TriggerException extends \RuntimeException
{
    /**
     * @param list<string|int> $labelArguments
     */
    private function __construct(
        string $message,
        public readonly string $labelKey,
        public readonly array $labelArguments = [],
    ) {
        parent::__construct($message);
    }

    public static function noUrl(): self
    {
        return new self('No address is stored for this instance.', 'trigger.noUrl');
    }

    public static function unreachable(string $url, string $detail): self
    {
        return new self(sprintf('Instance unreachable at %s: %s', $url, $detail), 'trigger.unreachable', [$detail]);
    }

    public static function cooldown(int $seconds): self
    {
        return new self(sprintf('The instance has just reported, %d seconds to wait.', $seconds), 'trigger.cooldown', [$seconds]);
    }

    /**
     * The instance sits behind HTTP Basic Auth and either got no credentials
     * or the wrong ones.
     */
    public static function unauthorized(bool $credentialsWereSent): self
    {
        return $credentialsWereSent
            ? new self('The instance rejected the Basic Auth credentials.', 'trigger.unauthorizedWrong')
            : new self('The instance asks for HTTP Basic Auth.', 'trigger.unauthorizedMissing');
    }

    public static function refused(string $detail): self
    {
        return new self('The instance refused: ' . $detail, 'trigger.refused', [$detail]);
    }
}
