ALTER TABLE llx_inbox_tag ADD UNIQUE INDEX uk_inbox_tag_label (entity, label);
ALTER TABLE llx_inbox_tag ADD INDEX idx_inbox_tag_user (fk_user_creat);
