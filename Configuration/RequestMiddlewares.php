<?php

declare(strict_types=1);

use Caretaker2\Hub\Http\ApiMiddleware;

return [
    'frontend' => [
        'caretaker2/api' => [
            'target' => ApiMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            // Ahead of site resolution: the API has to answer even when the hub
            // has no site configured at all.
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
