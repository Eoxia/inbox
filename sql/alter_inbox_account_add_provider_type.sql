-- Add provider_type column to llx_inbox_account.
-- Existing rows default to 'imap' (backward-compatible).
ALTER TABLE llx_inbox_account
    ADD COLUMN provider_type VARCHAR(30) NOT NULL DEFAULT 'imap'
    AFTER oauth_service;
