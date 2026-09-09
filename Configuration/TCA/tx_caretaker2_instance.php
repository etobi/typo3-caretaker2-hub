<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.title',
        'label' => 'title',
        'label_alt' => 'instance_url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'title',
        'rootLevel' => 1,
        'iconfile' => 'EXT:caretaker2_hub/Resources/Public/Icons/module-caretaker2.svg',
        'enablecolumns' => [],
        'searchFields' => 'title,instance_url,site_hosts',
        'hideTable' => false,
        'readOnly' => false,
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.title',
            'config' => ['type' => 'input', 'size' => 40, 'eval' => 'trim', 'required' => true],
        ],
        'instance_group' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.group',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_caretaker2_group',
                'foreign_table_where' => 'ORDER BY tx_caretaker2_group.title',
                'items' => [['label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:list.group.ungrouped', 'value' => 0]],
                'default' => 0,
            ],
        ],
        'instance_url' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.url',
            'config' => ['type' => 'input', 'size' => 60, 'eval' => 'trim', 'readOnly' => true],
        ],
        'typo3_version' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.typo3',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'php_version' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.php',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'db_version' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.database',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'application_context' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.context',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'agent_version' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.agentVersion',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'last_seen' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.lastSeen',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'site_hosts' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.field.hosts',
            'config' => ['type' => 'text', 'rows' => 4, 'readOnly' => true],
        ],
        'tenant' => [
            'label' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.tenant',
            'config' => ['type' => 'number', 'default' => 1, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, instance_group,
                --div--;LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:tca.instance.tab.reported, instance_url, typo3_version, php_version, db_version,
                application_context, agent_version, last_seen, site_hosts',
        ],
    ],
];
