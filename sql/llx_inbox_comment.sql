CREATE TABLE IF NOT EXISTS llx_inbox_comment (
	rowid         integer      NOT NULL AUTO_INCREMENT,
	entity        integer      NOT NULL DEFAULT 1,
	fk_account    integer      NOT NULL,
	folder        varchar(255) NOT NULL DEFAULT 'INBOX',
	message_uid   integer      NOT NULL,
	message_id    varchar(512)          DEFAULT NULL,
	comment       text         NOT NULL,
	status        smallint     NOT NULL DEFAULT 1,
	fk_user_creat integer      NOT NULL,
	fk_user_modif integer               DEFAULT NULL,
	date_creation datetime              DEFAULT NULL,
	tms           timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (rowid)
) ENGINE=InnoDB;
