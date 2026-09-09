<?php

declare(strict_types=1);

use Caretaker2\Hub\Http\ApiMiddleware;

return [
    'frontend' => [
        'caretaker2/api' => [
            'target' => ApiMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            // Vor der Site-Auflösung: Die API muss auch dann antworten,
            // wenn der Hub gar keine Site konfiguriert hat.
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
