<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Where a TYPO3 major stands in its life cycle.
 */
enum Typo3Support: string
{
    case STABLE = 'stable';
    case OLDSTABLE = 'oldstable';
    case ELTS = 'elts';

    /** Inside the ELTS window, but on the last free release: gets nothing. */
    case ELTS_UNPATCHED = 'elts_unpatched';
    case UNSUPPORTED = 'unsupported';
    case UNKNOWN = 'unknown';

    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function getLabelKey(): ?string
    {
        return match ($this) {
            self::STABLE => self::LL . 'typo3.status.stable',
            self::OLDSTABLE => self::LL . 'typo3.status.oldstable',
            self::ELTS => self::LL . 'typo3.status.elts',
            self::ELTS_UNPATCHED => self::LL . 'typo3.status.eltsUnpatched',
            self::UNSUPPORTED => self::LL . 'typo3.status.unsupported',
            self::UNKNOWN => null,
        };
    }

    public function getColour(): string
    {
        return match ($this) {
            self::STABLE => 'success',
            self::OLDSTABLE => 'info',
            self::ELTS => 'warning',
            self::ELTS_UNPATCHED, self::UNSUPPORTED => 'danger',
            self::UNKNOWN => 'secondary',
        };
    }
}
