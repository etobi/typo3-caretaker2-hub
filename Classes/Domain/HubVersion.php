<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The version of this extension, read from ext_emconf.php so it is written
 * down in one place only. Hub and agent are released together under the
 * same number, so this is also the version an agent is expected to run.
 */
final class HubVersion
{
    public function current(): string
    {
        $version = ExtensionManagementUtility::getExtensionVersion('caretaker2_hub');

        return $version !== '' ? $version : '0.0.0';
    }
}
