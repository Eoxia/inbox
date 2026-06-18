ALTER TABLE llx_inbox_message_tag ADD UNIQUE INDEX uk_inbox_msg_tag (fk_tag, fk_account, message_id(255));
ALTER TABLE llx_inbox_message_tag ADD INDEX idx_inbox_msg_tag_msg (fk_account, message_id(255));
ALTER TABLE llx_inbox_message_tag ADD INDEX idx_inbox_msg_tag_fk (fk_tag);
