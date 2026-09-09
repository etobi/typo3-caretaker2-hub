<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Caretaker2\Hub\Hook\DataHandlerHook;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

// There is no PSR-14 event for deleting records. This hook is the only way,
// and it carries unchanged from v11 to v14.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = DataHandlerHook::class;

// The support windows from get.typo3.org change a few times a year.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['caretaker2'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 86400],
];
