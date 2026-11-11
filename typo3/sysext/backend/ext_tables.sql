CREATE TABLE be_users (
	# No TCA column defined
	password_reset_token varchar(128) DEFAULT '' NOT NULL
);

CREATE TABLE be_session_transfer (
	identifier varchar(64) DEFAULT '' NOT NULL,
	expires bigint(20) unsigned DEFAULT '0' NOT NULL,
	purpose varchar(32) DEFAULT '' NOT NULL,
	audience varchar(512) DEFAULT '' NOT NULL,
	data mediumtext,

	PRIMARY KEY (identifier),
	KEY expires (expires)
);
