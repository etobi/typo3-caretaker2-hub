<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.group.title',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'rootLevel' => 1,
        'iconfile' => 'EXT:caretaker2_hub/Resources/Public/Icons/module-caretaker2.svg',
        'enablecolumns' => [],
        'searchFields' => 'title,description',
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.group.field.title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.group.field.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
            ],
        ],
        'tenant' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.tenant',
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
