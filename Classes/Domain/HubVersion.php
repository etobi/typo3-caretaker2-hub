<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The version of this extension. TYPO3 takes it from the Composer package in
 * Composer mode, so it is the released tag, and from composer.json in classic
 * mode, where the release workflow writes the same number. Hub and agent are
 * released together under one number, so this is also the version an agent
 * is expected to run.
 */
final class HubVersion
{
    public function current(): string
    {
        $version = ExtensionManagementUtility::getExtensionVersion('caretaker2_hub');

        return $version !== '' ? $version : '0.0.0';
    }
}
