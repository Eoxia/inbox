CREATE TABLE llx_inbox_email(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_inbox_account integer NOT NULL,
	imap_uid varchar(255) NOT NULL,
	message_id varchar(255) NOT NULL,
	subject varchar(255),
	sender varchar(255),
	receiver varchar(255),
	date_email datetime,
	status integer DEFAULT 0,
	body_text text,
	body_html text,
	tms timestamp
) ENGINE=innodb;
