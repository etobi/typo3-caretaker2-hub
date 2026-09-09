<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Caretaker2\Hub\Hook\DataHandlerHook;

// Kein PSR-14-Event für das Löschen von Datensätzen — dieser Hook ist der
// einzige Weg, und er trägt unverändert von v11 bis v14.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = DataHandlerHook::class;
