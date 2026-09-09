#
# A monitored instance.
#
CREATE TABLE tx_caretaker2_instance (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,
    instance_url varchar(2048) DEFAULT '' NOT NULL,

    # The hash only. The hub sees the token itself exactly once, when issuing
    # it; afterwards it is readable nowhere.
    token_hash varchar(64) DEFAULT '' NOT NULL,

    # The tenant. Will read 1 for years, but has to be here from the start:
    # threading it through a grown data model later is weeks of work.
    tenant int(11) unsigned DEFAULT 1 NOT NULL,

    # An instance belongs to at most one group. Deliberately not a multiple
    # assignment: grouping means dividing. Tags would be a different thing.
    instance_group int(11) unsigned DEFAULT 0 NOT NULL,

    agent_version varchar(32) DEFAULT '' NOT NULL,
    schema_version int(11) unsigned DEFAULT 0 NOT NULL,
    typo3_version varchar(32) DEFAULT '' NOT NULL,
    typo3_major int(11) unsigned DEFAULT 0 NOT NULL,
    application_context varchar(64) DEFAULT '' NOT NULL,
    php_version varchar(32) DEFAULT '' NOT NULL,
    db_platform varchar(32) DEFAULT '' NOT NULL,
    db_version varchar(64) DEFAULT '' NOT NULL,

    # The worst provider status in this inventory. An instance where one
    # provider could deliver nothing must not look like a clean one.
    worst_provider_status varchar(16) DEFAULT '' NOT NULL,

    # Every domain of the instance, one per line. That makes "where does
    # kunde-zwei.fr live" a query instead of a search through all snapshots.
    site_hosts text,
    site_count int(11) unsigned DEFAULT 0 NOT NULL,

    last_seen int(11) unsigned DEFAULT 0 NOT NULL,
    last_fingerprint varchar(64) DEFAULT '' NOT NULL,

    # The inventory received last, always overwritten. Kept apart from the
    # snapshots because the two answer different questions: this one "what is
    # now", the snapshots "what changed when".
    last_inventory mediumtext,

    # An evaluation takes seconds to minutes and therefore cannot run inside
    # the push request. Receiving only sets the mark; a scheduler run works
    # through it.
    needs_evaluation tinyint(1) unsigned DEFAULT 0 NOT NULL,
    evaluated_at int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY token_hash (token_hash),
    KEY tenant_seen (tenant, last_seen),
    KEY pending (needs_evaluation, evaluated_at)
);

#
# An inventory snapshot. Written only when the fingerprint differs from the
# previous one, so the history of changes falls out as a chain of differences
# without being maintained separately.
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
# A short-lived enrollment code, traded for a lasting token.
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

#
# A finding. Reconciled on every evaluation: known findings keep their
# first_seen and their acknowledgement, vanished ones are removed.
#
CREATE TABLE tx_caretaker2_finding (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,

    instance int(11) unsigned DEFAULT 0 NOT NULL,
    tenant int(11) unsigned DEFAULT 1 NOT NULL,

    # security | update_safe | update_major | abandoned | unassessable
    finding_type varchar(24) DEFAULT '' NOT NULL,
    severity varchar(16) DEFAULT '' NOT NULL,

    # Unique per instance together with the type. The advisory id for security
    # findings, the package name otherwise.
    identifier varchar(190) DEFAULT '' NOT NULL,
    package varchar(190) DEFAULT '' NOT NULL,
    installed_version varchar(64) DEFAULT '' NOT NULL,
    latest_version varchar(64) DEFAULT '' NOT NULL,

    # Either a finished sentence — an advisory title from Packagist, a message
    # from TYPO3's own checks — or an LLL key whose placeholders come from
    # title_args. Translated when it is shown, not before.
    title text,
    title_args text,
    link varchar(2048) DEFAULT '' NOT NULL,

    first_seen int(11) unsigned DEFAULT 0 NOT NULL,
    last_seen int(11) unsigned DEFAULT 0 NOT NULL,

    # An acknowledgement without an expiry date. The note is the actual value:
    # in half a year the question is why somebody waved this through.
    acknowledged tinyint(1) unsigned DEFAULT 0 NOT NULL,
    ack_note text,
    ack_user varchar(190) DEFAULT '' NOT NULL,
    ack_at int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY finding_key (instance, finding_type, identifier),
    KEY tenant_severity (tenant, severity)
);

#
# Eine Gruppe von Instanzen — in der Regel ein Kunde, aber die Bezeichnung
# bleibt offen, damit auch nach Umgebung oder Team gruppiert werden kann.
#
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
