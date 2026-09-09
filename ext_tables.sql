CREATE TABLE tx_caretaker2_instance (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,
    instance_url varchar(2048) DEFAULT '' NOT NULL,
    token_hash varchar(64) DEFAULT '' NOT NULL,
    tenant int(11) unsigned DEFAULT 1 NOT NULL,
    instance_group int(11) unsigned DEFAULT 0 NOT NULL,
    agent_version varchar(32) DEFAULT '' NOT NULL,
    schema_version int(11) unsigned DEFAULT 0 NOT NULL,
    typo3_version varchar(32) DEFAULT '' NOT NULL,
    typo3_major int(11) unsigned DEFAULT 0 NOT NULL,
    application_context varchar(64) DEFAULT '' NOT NULL,
    php_version varchar(32) DEFAULT '' NOT NULL,
    db_platform varchar(32) DEFAULT '' NOT NULL,
    db_version varchar(64) DEFAULT '' NOT NULL,
    worst_provider_status varchar(16) DEFAULT '' NOT NULL,
    site_hosts text,
    site_count int(11) unsigned DEFAULT 0 NOT NULL,

    last_seen int(11) unsigned DEFAULT 0 NOT NULL,
    last_fingerprint varchar(64) DEFAULT '' NOT NULL,
    last_inventory mediumtext,
    needs_evaluation tinyint(1) unsigned DEFAULT 0 NOT NULL,
    evaluated_at int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY token_hash (token_hash),
    KEY tenant_seen (tenant, last_seen),
    KEY pending (needs_evaluation, evaluated_at)
);

CREATE TABLE tx_caretaker2_snapshot (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    instance int(11) unsigned DEFAULT 0 NOT NULL,
    tenant int(11) unsigned DEFAULT 1 NOT NULL,
    fingerprint varchar(64) DEFAULT '' NOT NULL,
    payload mediumtext,

    PRIMARY KEY (uid),
    KEY instance_created (instance, crdate)
);

CREATE TABLE tx_caretaker2_enrollment (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    code varchar(16) DEFAULT '' NOT NULL,
    tenant int(11) unsigned DEFAULT 1 NOT NULL,
    valid_until int(11) unsigned DEFAULT 0 NOT NULL,
    redeemed_at int(11) unsigned DEFAULT 0 NOT NULL,
    instance int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY code (code)
);

CREATE TABLE tx_caretaker2_finding (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,

    instance int(11) unsigned DEFAULT 0 NOT NULL,
    tenant int(11) unsigned DEFAULT 1 NOT NULL,

    finding_type varchar(24) DEFAULT '' NOT NULL,
    severity varchar(16) DEFAULT '' NOT NULL,

    identifier varchar(190) DEFAULT '' NOT NULL,
    package varchar(190) DEFAULT '' NOT NULL,
    installed_version varchar(64) DEFAULT '' NOT NULL,
    latest_version varchar(64) DEFAULT '' NOT NULL,

    title text,
    title_args text,
    link varchar(2048) DEFAULT '' NOT NULL,

    first_seen int(11) unsigned DEFAULT 0 NOT NULL,
    last_seen int(11) unsigned DEFAULT 0 NOT NULL,

    acknowledged tinyint(1) unsigned DEFAULT 0 NOT NULL,
    ack_note text,
    ack_user varchar(190) DEFAULT '' NOT NULL,
    ack_at int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY finding_key (instance, finding_type, identifier),
    KEY tenant_severity (tenant, severity)
);

CREATE TABLE tx_caretaker2_group (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,

    tenant int(11) unsigned DEFAULT 1 NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    description text,

    PRIMARY KEY (uid),
    KEY tenant_sorting (tenant, sorting)
);
