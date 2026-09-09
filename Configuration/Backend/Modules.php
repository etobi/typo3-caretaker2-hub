<?php

declare(strict_types=1);

use Caretaker2\Hub\Controller\InstanceListController;

return [
    'caretaker2' => [
        'labels' => [
            'title' => 'Caretaker2',
        ],
        'iconIdentifier' => 'caretaker2-module',
        'position' => ['after' => 'web'],
    ],
    'caretaker2_instances' => [
        'parent' => 'caretaker2',
        'position' => ['top'],
        'access' => 'admin',
        'path' => '/module/caretaker2/instances',
        'iconIdentifier' => 'caretaker2-module',
        'labels' => [
            'title' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:module.instances.title',
            'description' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:module.instances.description',
        ],
        'routes' => [
            '_default' => [
                'target' => InstanceListController::class . '::handleRequest',
            ],
        ],
    ],
];
