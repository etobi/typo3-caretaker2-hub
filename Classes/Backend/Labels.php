<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The hub's own labels, translated for the current backend user.
 */
final class Labels
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    /**
     * A key from the hub's locallang, with its placeholders filled in.
     */
    public function get(string $key, string|int ...$arguments): string
    {
        return $this->translate(self::LL . $key, ...$arguments);
    }

    /**
     * A full LLL reference, for keys that arrive from elsewhere: stored
     * finding titles, for instance.
     */
    public function translate(string $reference, string|int ...$arguments): string
    {
        $text = (string)$this->languageService()->sL($reference);

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }

    private function languageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
