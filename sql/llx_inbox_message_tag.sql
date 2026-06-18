CREATE TABLE IF NOT EXISTS llx_inbox_message_tag (
	rowid         integer      NOT NULL AUTO_INCREMENT,
	entity        integer      NOT NULL DEFAULT 1,
	fk_tag        integer      NOT NULL,
	fk_account    integer      NOT NULL,
	message_id    varchar(512) NOT NULL,
	message_uid   integer      DEFAULT NULL,
	folder        varchar(255) DEFAULT NULL,
	fk_user_creat integer      NOT NULL,
	date_creation datetime     DEFAULT NULL,
	PRIMARY KEY (rowid)
) ENGINE=InnoDB;
