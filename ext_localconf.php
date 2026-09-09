<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Caretaker2\Hub\Hook\DataHandlerHook;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

// Kein PSR-14-Event für das Löschen von Datensätzen — dieser Hook ist der
// einzige Weg, und er trägt unverändert von v11 bis v14.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = DataHandlerHook::class;

// Die Support-Zeiträume von get.typo3.org ändern sich ein paar Mal im Jahr.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['caretaker2'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 86400],
];
