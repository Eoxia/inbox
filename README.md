# Inbox — Native collaborative webmail for Dolibarr

Inbox is a custom Dolibarr module that embeds a full-featured webmail interface directly inside your ERP. It connects to any IMAP/SMTP mailbox and lets your team read, reply to, tag, and annotate emails without leaving Dolibarr.

---

## Features

- **4-panel interface** — folders sidebar, message list, email view, ERP context panel
- **Multiple accounts** — shared (team) or personal (per-user) mailboxes
- **HTML email rendering** — safe sandboxed iframe display
- **Remote image blocking** — images blocked by default, revealed on demand
- **Send & reply** — with a configurable cancellation delay before actual delivery
- **Attachments** — inline list with one-click download
- **Move to trash / delete** — with configurable trash folder per account
- **Tags** — admin-defined labels with optional IMAP keyword sync (visible in other mail clients)
- **Internal comments** — team notes attached to a message, never sent to the recipient
- **Read/unread tracking** — `\Seen` flag persisted on the IMAP server
- **Folder navigation** — browse all IMAP folders of the account
- **Auto-refresh** — configurable polling interval
- **OAuth2 / XOAUTH2** — passwordless authentication for Gmail and Microsoft Exchange Online

---

## Requirements

| Component | Version |
|---|---|
| Dolibarr | 17.0 or later |
| PHP | 7.4 or later (8.1+ recommended) |
| Composer | any recent version |
| MariaDB / MySQL | 5.7 / 10.3 or later |
| PHP `imap` extension | **not required** (Horde socket library used instead) |

---

## Installation

### 1 — Copy the module

Place the `inbox/` directory under `htdocs/custom/`:

```
dolibarr/htdocs/custom/inbox/
```

### 2 — Install PHP dependencies

```bash
cd htdocs/custom/inbox
composer install --no-dev
```

This installs `bytestream/horde-imap-client` (v2.34), the only runtime dependency. No PHP `imap` extension is needed.

### 3 — Enable the module

Log in as Dolibarr administrator, go to **Home → Setup → Modules/Applications**, find **Inbox** (CRM family) and click **Activate**.

Enabling the module automatically creates the required database tables.

### 4 — Existing installation: apply the ALTER TABLE migration

If you are upgrading from a version prior to OAuth2 support, apply the schema migration manually:

```bash
# Docker (adapt credentials as needed)
docker compose exec -T mariadb mariadb -u root -p<password> <dbname> \
  -e "ALTER TABLE llx_inbox_account ADD COLUMN IF NOT EXISTS auth_type varchar(20) DEFAULT 'password' AFTER status;
      ALTER TABLE llx_inbox_account ADD COLUMN IF NOT EXISTS oauth_service varchar(50) DEFAULT '' AFTER auth_type;"
```

Or run the file `sql/llx_inbox_account-alter.sql` directly against your database.

---

## Configuration

Go to **Home → Setup → Modules → Inbox** (or click the wrench icon next to the module).

### Email accounts

Each account stores IMAP (incoming) and SMTP (outgoing) credentials.

| Field | Description |
|---|---|
| Label | Display name shown in the sidebar |
| Email | Sender address for outgoing mail |
| Shared | If enabled, the account is visible to all internal users |
| IMAP server / port / security | Incoming mail server settings |
| SMTP server / port / security | Outgoing mail server settings |
| Login | IMAP/SMTP username (usually the email address) |
| Authentication mode | `Password` (standard) or `OAuth2` (see below) |
| Sync limit — count | Maximum messages to fetch per refresh (default: 500) |
| Sync limit — days | Only fetch messages newer than N days (default: 180) |

### OAuth2 authentication (Gmail / Microsoft Exchange Online)

OAuth2 allows Dolibarr to connect to IMAP without storing a password. The access token is refreshed automatically.

**Supported providers:**

| Provider | Dolibarr service key | Required IMAP scope |
|---|---|---|
| Google (Gmail) | `GOOGLE` | `https://mail.google.com/` (short: `gmail_full`) |
| Microsoft Exchange Online | `MICROSOFT3` | `https://outlook.office.com/IMAP.AccessAsUser.All` |

**Setup procedure:**

1. **Register an OAuth2 application** with Google or Microsoft (links are provided on Dolibarr's OAuth2 page).
2. **Configure the provider** in Dolibarr: **Home → Setup → OAuth2 services** (`/admin/oauth.php`). Enter the Client ID, Client Secret, and select the required scopes.
3. **Authorize the token**: on the same OAuth2 page, click the authorization link for your provider and complete the OAuth2 flow. Dolibarr stores the token in `llx_oauth_token`.
4. **Create or edit an inbox account**: in the Inbox setup page, choose `OAuth2` as the authentication mode, then select the provider from the dropdown. The password field is ignored in this mode.

> The OAuth2 dropdown only shows providers that are already configured with credentials **and** have IMAP-compatible scopes. If the dropdown is empty, first complete step 2.

### Tags

Go to the **Tags** tab of the Inbox setup page. Tags are defined globally by an administrator and can be assigned to any message.

| Field | Description |
|---|---|
| Label | Tag display name |
| Color | Hex color code (used in the UI badge) |
| IMAP keyword | Optional ASCII keyword stored on the IMAP server (no spaces, synced across mail clients) |

### Global settings

| Setting | Default | Description |
|---|---|---|
| Send cancellation delay | 10 s | Seconds before an email is actually sent — allows the user to abort |
| Auto-refresh interval | 0 (off) | Seconds between automatic email list refreshes (0 = disabled) |
| Block remote images | Yes | Block external images in emails by default |

---

## Database schema

The module creates five tables (all prefixed with `llx_`):

| Table | Purpose |
|---|---|
| `llx_inbox_account` | IMAP/SMTP account credentials and settings |
| `llx_inbox_tag` | Admin-defined tags |
| `llx_inbox_message_tag` | Many-to-many: tag assignments per message |
| `llx_inbox_email` | Cached message metadata (UID, subject, sender, flags…) |
| `llx_inbox_comment` | Internal team comments attached to a message |

---

## Permissions

| Permission key | ID | Default | Description |
|---|---|---|---|
| `inbox.read` | 104501 | **Yes** | Read emails and access the webmail |
| `inbox.write` | 104502 | No | Send emails and manage accounts |
| `inbox.delete` | 104503 | No | Delete or move messages to trash |
| `inbox.setup` | 104504 | No | Access the module configuration pages |

---

## Interface overview

The webmail is accessible from the **Inbox** top-level menu item. The screen is divided into four panels:

```
┌─────────────┬──────────────────┬──────────────────────────┬────────────────┐
│  Sidebar    │  Message list    │  Email view              │  ERP context   │
│             │                  │                          │                │
│ Mailboxes   │ Unread / read    │ Subject, sender, date    │ Linked docs    │
│ Folders     │ Subject snippet  │ Remote image banner      │                │
│             │ Date / tags      │ HTML body (sandboxed)    │ Internal       │
│             │                  │ Attachments bar          │ comments       │
│             │                  │ Reply form               │                │
└─────────────┴──────────────────┴──────────────────────────┴────────────────┘
```

**Sidebar** — lists all IMAP folders of the active account. Click a folder to load its messages.

**Message list** — shows messages with unread/read indicator, sender, subject, date, and assigned tags. Scroll to load more (pagination via offset).

**Email view** — displays the selected message. Remote images are blocked by default; a yellow banner lets the user reveal them on demand. Attachments appear in a bar below the body. The reply form is toggled with the Reply button.

**ERP context** — reserved panel for linking Dolibarr documents (quotes, invoices, etc.) and adding internal team comments to a message.

---

## Technical notes

### IMAP library

The module uses **`bytestream/horde-imap-client`** (v2.34) instead of the PHP `imap` extension. This library communicates directly over a TCP/TLS socket, supports IMAP4rev1, and implements `CONDSTORE`/`QRESYNC` for efficient delta synchronisation.

### XOAUTH2

When `auth_type = 'oauth2'`, the `IMAPClient::connect()` method calls `resolveOAuthToken()`, which:

1. Extracts the `keyforprovider` suffix from `oauth_service` (e.g. `GOOGLE-work` → `keyforprovider = 'work'`).
2. Loads the stored token from `llx_oauth_token` via `DoliStorage`.
3. Refreshes the access token if expired (preserving the refresh token).
4. Returns a `Horde_Imap_Client_Password_Xoauth2` object passed to the Horde client as `xoauth2_token`.

### SMTP sending

Outgoing emails are sent through Dolibarr's **`CMailFile`** class, which handles SMTP connection, TLS, and authentication natively. The account's SMTP credentials are temporarily injected into `$conf->global` for the duration of the send call.

### Read/unread tracking

Messages are fetched with `peek => true` (does not auto-mark as read on the server). When the user clicks a message, a fire-and-forget AJAX call to `ajax/mark_seen.php` sends an IMAP `STORE` command (`+FLAGS \Seen`).

### Remote image blocking

Before injecting HTML into the sandboxed iframe, JavaScript replaces `src="https://..."` attributes with `data-original-src`. Clicking **Show images** restores the original `src` attributes via DOM manipulation inside the iframe (permitted by `allow-same-origin` in the sandbox).

---

## File structure

```
inbox/
├── admin/
│   ├── setup.php          # Account management + global settings
│   └── tags.php           # Tag management
├── ajax/
│   ├── get_folders.php    # List IMAP folders
│   ├── get_emails.php     # Paginated message list
│   ├── get_email_body.php # Full message body + attachments list
│   ├── get_attachment.php # Download a specific attachment
│   ├── mark_seen.php      # Set \Seen flag on IMAP server
│   ├── trash_email.php    # Move to trash / delete
│   ├── send_email.php     # Send / reply via SMTP
│   ├── get_tags.php       # List all available tags
│   ├── get_message_tags.php   # Tags for a given message
│   ├── add_message_tag.php    # Assign a tag to a message
│   ├── remove_message_tag.php # Remove a tag from a message
│   ├── get_comments.php   # Internal comments for a message
│   ├── add_comment.php    # Post an internal comment
│   └── delete_comment.php # Delete an internal comment
├── class/
│   ├── imapclient.class.php      # Horde IMAP wrapper + OAuth2 token resolution
│   ├── inboxaccount.class.php    # Account ORM (fetch / create)
│   ├── inboxemail.class.php      # Cached email metadata
│   ├── inboxtag.class.php        # Tag ORM
│   ├── inboxmessagetag.class.php # Tag-message association ORM
│   └── inboxcomment.class.php    # Internal comment ORM
├── core/modules/
│   └── modInbox.class.php        # Module descriptor (menus, permissions, tables)
├── css/
│   └── inbox.css                 # UI styles
├── js/
│   └── app.js                    # Single-page frontend logic
├── langs/
│   ├── en_US/inbox.lang          # English translations
│   └── fr_FR/inbox.lang          # French translations
├── lib/
│   └── inbox.lib.php             # Admin tab helper
├── sql/
│   ├── llx_inbox_account.sql          # Account table
│   ├── llx_inbox_tag.sql              # Tag table
│   ├── llx_inbox_message_tag.sql      # Tag-message table
│   ├── llx_inbox_email.sql            # Cached email table
│   ├── llx_inbox_comment.sql          # Comment table
│   └── llx_inbox_account-alter.sql    # Migration: auth_type / oauth_service columns
├── vendor/                        # Composer dependencies (not committed)
├── composer.json
├── index.php                      # Main webmail page
├── README.md
└── README-FR.md
```

---

## License

This module is distributed under the **GNU General Public License v3 (GPL-3.0)**, the same license as Dolibarr itself.
