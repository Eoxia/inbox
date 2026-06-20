ALTER TABLE llx_inbox_account ADD COLUMN auth_type varchar(20) DEFAULT 'password' AFTER status;
ALTER TABLE llx_inbox_account ADD COLUMN oauth_service varchar(50) DEFAULT '' AFTER auth_type;
