CREATE TABLE tx_recordwrite_item (
	title varchar(255) DEFAULT '' NOT NULL,
	body text DEFAULT NULL,
	amount int DEFAULT 0 NOT NULL,
	email varchar(255) DEFAULT '' NOT NULL,
	link varchar(2048) DEFAULT '' NOT NULL,
	flags int DEFAULT 0 NOT NULL,
	synchronized varchar(255) DEFAULT '' NOT NULL,
	readonly_value varchar(255) DEFAULT '' NOT NULL,
	restricted varchar(255) DEFAULT '' NOT NULL,
	parent int unsigned DEFAULT 0 NOT NULL,
	children int unsigned DEFAULT 0 NOT NULL,
	related int unsigned DEFAULT 0 NOT NULL,
	references text DEFAULT '' NOT NULL,
	selected text DEFAULT '' NOT NULL,
	reference varchar(255) DEFAULT '' NOT NULL
);
CREATE TABLE tx_recordwrite_item_mm (
	uid_local int unsigned DEFAULT 0 NOT NULL,
	uid_foreign int unsigned DEFAULT 0 NOT NULL,
	sorting int unsigned DEFAULT 0 NOT NULL,
	sorting_foreign int unsigned DEFAULT 0 NOT NULL,
	KEY uid_local (uid_local),
	KEY uid_foreign (uid_foreign)
);
