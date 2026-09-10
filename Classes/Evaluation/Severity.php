<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

/**
 * How bad a finding is. Declared most severe first: that order is the rank.
 */
enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';

    /**
     * No rating yet. New advisories reach the GitHub Advisory Database with a
     * delay, and until then the finding counts as a serious one.
     */
    case UNKNOWN = 'unknown';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case INFO = 'info';

    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    /**
     * The rating an advisory brings along. Anything we do not recognise is
     * unrated, not harmless.
     */
    public static function fromAdvisory(mixed $value): self
    {
        $rated = is_string($value) ? self::tryFrom(strtolower($value)) : null;

        return in_array($rated, [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW], true)
            ? $rated
            : self::UNKNOWN;
    }

    /**
     * A value read back from the database.
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::INFO;
    }

    /**
     * Zero for the most severe.
     */
    public function getRank(): int
    {
        return (int)array_search($this, self::cases(), true);
    }

    public function outranks(self $other): bool
    {
        return $this->getRank() < $other->getRank();
    }

    /**
     * Serious enough to mark the whole instance as vulnerable.
     */
    public function isSerious(): bool
    {
        return $this->getRank() <= self::UNKNOWN->getRank();
    }

    public function getColour(): string
    {
        return match ($this) {
            self::CRITICAL, self::HIGH, self::UNKNOWN => 'danger',
            self::MEDIUM => 'warning',
            self::LOW => 'info',
            self::INFO => 'secondary',
        };
    }

    public function getLabelKey(): string
    {
        return self::LL . 'finding.severity.' . $this->value;
    }

    public function getHintKey(): ?string
    {
        return $this === self::UNKNOWN ? self::LL . 'finding.severity.unknownHint' : null;
    }
}
