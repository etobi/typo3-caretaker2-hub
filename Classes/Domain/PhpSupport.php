<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Where a PHP release branch stands in its life cycle.
 */
enum PhpSupport: string
{
    case ACTIVE = 'active';
    case SECURITY = 'security';
    case EOL = 'eol';
    case UNKNOWN = 'unknown';

    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function getLabelKey(): ?string
    {
        return match ($this) {
            self::ACTIVE => self::LL . 'php.status.active',
            self::SECURITY => self::LL . 'php.status.security',
            self::EOL => self::LL . 'php.status.eol',
            self::UNKNOWN => null,
        };
    }

    public function getColour(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::SECURITY => 'warning',
            self::EOL => 'danger',
            self::UNKNOWN => 'secondary',
        };
    }
}
