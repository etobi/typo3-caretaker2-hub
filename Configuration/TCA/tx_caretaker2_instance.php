<?php

declare(strict_types=1);

/**
 * The agent writes almost every field. Editable are the two that hold a
 * decision of the operator's: the display name and the group. The rest sits
 * next to them read-only, so that one record has everything together without
 * inviting an accidental overwrite.
 */
return [
    'ctrl' => [
        'title' => 'Caretaker2 Instanz',
        'label' => 'title',
        'label_alt' => 'instance_url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'title',
        'rootLevel' => 1,
        'iconfile' => 'EXT:caretaker2_hub/Resources/Public/Icons/module-caretaker2.svg',
        'enablecolumns' => [],
        'searchFields' => 'title,instance_url,site_hosts',
        // The token exists only as a hash, but a copied record would carry the
        // same one — and with it two instances reporting as the same.
        'hideTable' => false,
        'readOnly' => false,
    ],
    'columns' => [
        'title' => [
            'label' => 'Name',
            'config' => ['type' => 'input', 'size' => 40, 'eval' => 'trim', 'required' => true],
        ],
        'instance_group' => [
            'label' => 'Gruppe',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_caretaker2_group',
                'foreign_table_where' => 'ORDER BY tx_caretaker2_group.title',
                'items' => [['label' => 'ohne Gruppe', 'value' => 0]],
                'default' => 0,
            ],
        ],
        'instance_url' => [
            'label' => 'URL',
            'config' => ['type' => 'input', 'size' => 60, 'eval' => 'trim', 'readOnly' => true],
        ],
        'typo3_version' => [
            'label' => 'TYPO3',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'php_version' => [
            'label' => 'PHP',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'db_version' => [
            'label' => 'Datenbank',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'application_context' => [
            'label' => 'Application Context',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'agent_version' => [
            'label' => 'Agent-Version',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'last_seen' => [
            'label' => 'Zuletzt gemeldet',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'site_hosts' => [
            'label' => 'Domains',
            'config' => ['type' => 'text', 'rows' => 4, 'readOnly' => true],
        ],
        'tenant' => [
            'label' => 'Mandant',
            'config' => ['type' => 'number', 'default' => 1, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, instance_group,
                --div--;Gemeldet, instance_url, typo3_version, php_version, db_version,
                application_context, agent_version, last_seen, site_hosts',
        ],
    ],
];
