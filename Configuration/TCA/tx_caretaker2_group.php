<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Caretaker2 Gruppe',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        // Global records, not bound to a page.
        'rootLevel' => 1,
        'iconfile' => 'EXT:caretaker2_hub/Resources/Public/Icons/module-caretaker2.svg',
        'enablecolumns' => [],
        'searchFields' => 'title,description',
    ],
    'columns' => [
        'title' => [
            'label' => 'Name',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'Notiz',
            'config' => [
                'type' => 'text',
                'rows' => 3,
            ],
        ],
        'tenant' => [
            'label' => 'Mandant',
            'config' => [
                'type' => 'number',
                'default' => 1,
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, description'],
    ],
];
