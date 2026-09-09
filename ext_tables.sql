#
# Eine überwachte Instanz.
#
CREATE TABLE tx_caretaker2_instance (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,
    instance_url varchar(2048) DEFAULT '' NOT NULL,

    # Nur der Hash. Das Token selbst sieht der Hub genau einmal — beim
    # Ausstellen. Danach ist es nirgends mehr auslesbar.
    token_hash varchar(64) DEFAULT '' NOT NULL,

    # Mandant. Steht auf Jahre auf 1, muss aber von Anfang an da sein:
    # nachträglich in ein gewachsenes Datenmodell einzuziehen ist Wochenarbeit.
    tenant int(11) unsigned DEFAULT 1 NOT NULL,

    agent_version varchar(32) DEFAULT '' NOT NULL,
    schema_version int(11) unsigned DEFAULT 0 NOT NULL,
    typo3_version varchar(32) DEFAULT '' NOT NULL,
    typo3_major int(11) unsigned DEFAULT 0 NOT NULL,
    application_context varchar(64) DEFAULT '' NOT NULL,
    php_version varchar(32) DEFAULT '' NOT NULL,
    db_platform varchar(32) DEFAULT '' NOT NULL,
    db_version varchar(64) DEFAULT '' NOT NULL,

    # Der schlechteste Providerstatus dieses Inventars. Eine Instanz, bei der
    # ein Provider nichts liefern konnte, darf nicht wie eine saubere aussehen.
    worst_provider_status varchar(16) DEFAULT '' NOT NULL,

    last_seen int(11) unsigned DEFAULT 0 NOT NULL,
    last_fingerprint varchar(64) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY token_hash (token_hash),
    KEY tenant_seen (tenant, last_seen)
);

#
# Ein Inventar-Snapshot. Wird nur geschrieben, wenn sich der Fingerabdruck
# gegenüber dem vorherigen unterscheidet — die Update-Historie ergibt sich
# damit als Diff-Kette, ohne dass wir sie extra pflegen.
#
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

#
# Kurzlebiger Enrollment-Code. Ersetzt den Schlüsseltausch des Vorgängers.
#
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
