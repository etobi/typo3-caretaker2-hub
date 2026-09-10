<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * The one word the instance list says about an instance.
 */
enum InstanceState: string
{
    case OK = 'ok';

    /** Reporting fine, but findings are open. */
    case ATTENTION = 'attention';

    /** A provider delivered nothing or only parts. */
    case INCOMPLETE = 'incomplete';

    /** A serious security finding is open. */
    case VULNERABLE = 'vulnerable';

    /** TYPO3 or PHP receives no security updates any more. */
    case UNSUPPORTED = 'unsupported';

    /** Has not reported for too long. */
    case STALE = 'stale';

    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function getLabelKey(): string
    {
        return self::LL . 'state.' . $this->value;
    }

    public function getHintKey(): ?string
    {
        return $this === self::OK ? null : self::LL . 'state.hint.' . $this->value;
    }

    public function getSummaryKey(): string
    {
        return self::LL . 'list.summary.' . $this->value;
    }

    public function getColour(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::ATTENTION => 'secondary',
            self::INCOMPLETE => 'warning',
            self::VULNERABLE, self::UNSUPPORTED, self::STALE => 'danger',
        };
    }
}
