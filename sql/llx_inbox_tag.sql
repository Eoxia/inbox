CREATE TABLE IF NOT EXISTS llx_inbox_tag (
	rowid       integer      NOT NULL AUTO_INCREMENT,
	entity      integer      NOT NULL DEFAULT 1,
	label       varchar(50)  NOT NULL,
	color       varchar(7)   NOT NULL DEFAULT '#3b82f6',
	imap_keyword varchar(50) DEFAULT NULL,
	position    integer      NOT NULL DEFAULT 0,
	status      smallint     NOT NULL DEFAULT 1,
	fk_user_creat integer    NOT NULL,
	date_creation datetime   DEFAULT NULL,
	tms         timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (rowid)
) ENGINE=InnoDB;
