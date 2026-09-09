<?php

declare(strict_types=1);

use Caretaker2\Hub\Http\ApiMiddleware;

return [
    'frontend' => [
        'caretaker2/api' => [
            'target' => ApiMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
