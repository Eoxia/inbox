ALTER TABLE llx_inbox_email ADD INDEX idx_inbox_email_fk_inbox_account (fk_inbox_account);
ALTER TABLE llx_inbox_email ADD INDEX idx_inbox_email_message_id (message_id);
