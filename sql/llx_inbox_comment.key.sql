ALTER TABLE llx_inbox_comment ADD INDEX idx_inbox_comment_msg (fk_account, message_uid);
ALTER TABLE llx_inbox_comment ADD INDEX idx_inbox_comment_user (fk_user_creat);
