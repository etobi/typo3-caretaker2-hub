<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * What happened to one value between two snapshots.
 */
enum ChangeKind: string
{
    case ADDED = 'added';
    case REMOVED = 'removed';
    case CHANGED = 'changed';

    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function getLabelKey(): string
    {
        return self::LL . 'diff.kind.' . $this->value;
    }

    public function getColour(): string
    {
        return match ($this) {
            self::ADDED => 'success',
            self::REMOVED => 'danger',
            self::CHANGED => 'warning',
        };
    }
}
