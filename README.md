# Inbox — Native collaborative messaging hub for Dolibarr

Inbox is a custom Dolibarr module that embeds a full-featured messaging interface directly inside your ERP. It connects to any IMAP/SMTP mailbox or WhatsApp Business account and lets your team read, reply to, tag, and annotate messages without leaving Dolibarr.

---

## Features

- **4-panel interface** — folders sidebar, message list, message view, ERP context panel
- **Multiple accounts** — shared (team) or personal (per-user) mailboxes
- **Multi-provider architecture** — IMAP/SMTP, WhatsApp Business Cloud API; extensible to Graph, Gmail, SMS…
- **HTML email rendering** — safe sandboxed iframe display
- **Remote image blocking** — images blocked by default, revealed on demand
- **Send & reply** — with a configurable cancellation delay before actual delivery
- **Attachments** — inline list with one-click download
- **Move to trash / delete** — with configurable trash folder per account
- **Tags** — admin-defined labels with optional IMAP keyword sync (visible in other mail clients)
- **Internal comments** — team notes attached to a message, never sent to the recipient
- **Read/unread tracking** — `\Seen` flag persisted on the IMAP server (or local DB for WhatsApp)
- **Folder navigation** — browse all IMAP folders of the account
- **Auto-refresh** — configurable polling interval
- **OAuth2 / XOAUTH2** — passwordless authentication for Gmail and Microsoft Exchange Online
- **WhatsApp Business** — receive messages via Meta webhook, send text replies, download media

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

### 4 — Apply schema migrations (upgrades)

When upgrading from an older version, apply any missing migrations from the `sql/` folder:

```bash
# Docker — adapt credentials as needed
docker compose exec -T mariadb mariadb -u <user> -p<password> <dbname> \
  < htdocs/custom/inbox/sql/alter_inbox_account_add_provider_type.sql

docker compose exec -T mariadb mariadb -u <user> -p<password> <dbname> \
  < htdocs/custom/inbox/sql/alter_inbox_account_add_config.sql

docker compose exec -T mariadb mariadb -u <user> -p<password> <dbname> \
  < htdocs/custom/inbox/sql/create_inbox_whatsapp_message.sql
```

---

## Configuration

Go to **Home → Setup → Modules → Inbox** (or click the wrench icon next to the module).

### Email accounts (IMAP/SMTP)

Each account stores IMAP (incoming) and SMTP (outgoing) credentials.

| Field | Description |
|---|---|
| Label | Display name shown in the sidebar |
| Email | Sender address for outgoing mail |
| Provider type | `imap` (default), `whatsapp` |
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

### WhatsApp Business Cloud API

WhatsApp accounts receive messages via a Meta webhook (push model) and send via the Graph API. Messages are stored locally in `llx_inbox_whatsapp_message`.

#### 1 — Create a Meta App

1. Go to [developers.facebook.com](https://developers.facebook.com), create an app of type **Business**.
2. Add the **WhatsApp** product and configure a **Phone Number** in the WhatsApp Business Platform.
3. Generate a **System User Token** (permanent) with `whatsapp_business_messaging` permission.
4. Note the **Phone Number ID** from the WhatsApp → Getting Started page.

#### 2 — Create a WhatsApp account in Dolibarr

Go to **Home → Setup → Modules → Inbox**, click **Add account**, then select **WhatsApp Business Cloud API** as the provider type.

| Field | Description |
|---|---|
| Label | Display name in the sidebar |
| Phone Number ID | Phone Number ID from Meta Developer Console (WhatsApp → Getting Started) |
| Access Token | System User permanent token with `whatsapp_business_messaging` permission |
| Verify Token | Arbitrary secret you choose — must match what you enter in Meta's webhook configuration |
| API version | Optional — Graph API version, default `v20.0` |

Save the account. The webhook URL is displayed on the edit form once the account is saved.

#### 3 — Configure the Meta webhook

In Meta Developer Console → WhatsApp → Configuration:

- **Callback URL**: the webhook URL shown on the account edit form (format: `https://your-domain/custom/inbox/ajax/whatsapp_webhook.php?account_id=N`)
- **Verify Token**: the `verify_token` value from the config JSON above
- **Webhook fields**: subscribe to `messages`

#### 4 — Test

Send a WhatsApp message to your business number. It should appear in the Inbox within seconds.

### Tags

Go to the **Tags** tab of the Inbox setup page. Tags are global definitions (scoped to the Dolibarr entity) — they are not tied to a specific account and are available for all accounts.

| Field | Description |
|---|---|
| Label | Tag display name |
| Color | Hex color code (used in the UI badge) |
| IMAP keyword | Optional ASCII keyword stored on the IMAP server (no spaces, synced across mail clients) |

Tag *assignments* (which tag is on which message) are stored per-account in `llx_inbox_message_tag`.

> **IMAP keyword sync compatibility:** IMAP keywords are silently ignored for WhatsApp accounts. Gmail also does not support user-defined IMAP keywords — tags still appear in Dolibarr but are not synced to the Gmail interface. Keyword sync works reliably on Dovecot, Cyrus, and Exchange servers.

### Global settings

| Setting | Default | Description |
|---|---|---|
| Send cancellation delay | 10 s | Seconds before a message is actually sent — allows the user to abort |
| Auto-refresh interval | 0 (off) | Seconds between automatic message list refreshes (0 = disabled) |
| Block remote images | Yes | Block external images in emails by default |

---

## Database schema

| Table | Purpose |
|---|---|
| `llx_inbox_account` | Account credentials and settings (all provider types) |
| `llx_inbox_tag` | Admin-defined tags |
| `llx_inbox_message_tag` | Many-to-many: tag assignments per message |
| `llx_inbox_email` | Cached IMAP message metadata (UID, subject, sender, flags…) |
| `llx_inbox_comment` | Internal team comments attached to a message |
| `llx_inbox_whatsapp_message` | Local store for WhatsApp messages (incoming + outgoing) |

### `llx_inbox_account` — notable columns

| Column | Type | Description |
|---|---|---|
| `provider_type` | `VARCHAR(30)` | `imap` (default) or `whatsapp` |
| `config` | `TEXT` | JSON blob for provider-specific settings (WhatsApp: phone_number_id, access_token…) |
| `auth_type` | `VARCHAR(20)` | `password` or `oauth2` (IMAP only) |
| `oauth_service` | `VARCHAR(50)` | Dolibarr OAuth2 service key (IMAP only) |

### `llx_inbox_whatsapp_message`

| Column | Description |
|---|---|
| `wamid` | Meta message ID (`wamid.ABC…`) — unique per account |
| `direction` | `0` = incoming, `1` = outgoing |
| `from_phone` / `from_name` | Sender (incoming) |
| `to_phone` | Recipient (outgoing) |
| `msg_type` | `text`, `image`, `document`, `audio`, `video`, `sticker`, `location` |
| `body` | Text body or caption |
| `media_id` | Meta media object ID (for non-text messages) |
| `status` | `received`, `delivered`, `read`, `sent`, `failed`, `deleted` |

---

## Permissions

| Permission key | ID | Default | Description |
|---|---|---|---|
| `inbox.read` | 104501 | **Yes** | Read messages and access the hub |
| `inbox.write` | 104502 | No | Send messages and manage accounts |
| `inbox.delete` | 104503 | No | Delete or move messages to trash |
| `inbox.setup` | 104504 | No | Access the module configuration pages |

---

## Interface overview

The messaging hub is accessible from the **Inbox** top-level menu item. The screen is divided into four panels:

```
┌─────────────┬──────────────────┬──────────────────────────┬────────────────┐
│  Sidebar    │  Message list    │  Message view            │  ERP context   │
│             │                  │                          │                │
│ Accounts    │ Unread / read    │ Subject, sender, date    │ Linked docs    │
│ Folders /   │ Subject snippet  │ Remote image banner      │                │
│ Contacts    │ Date / tags      │ Body (sandboxed)         │ Internal       │
│             │                  │ Attachments bar          │ comments       │
│             │                  │ Reply form               │                │
└─────────────┴──────────────────┴──────────────────────────┴────────────────┘
```

**Sidebar** — lists mailboxes and, for IMAP accounts, all folders of the active account. For WhatsApp accounts, a single virtual **WhatsApp** folder is shown.

**Message list** — shows messages with unread/read indicator, sender, subject/preview, date, and assigned tags. Scroll to load more (pagination via offset).

**Message view** — displays the selected message. Remote images are blocked by default; a yellow banner lets the user reveal them on demand. Attachments appear in a bar below the body. The reply form is toggled with the Reply button.

**ERP context** — reserved panel for linking Dolibarr documents (quotes, invoices, etc.) and adding internal team comments to a message.

---

## Technical notes

### Provider abstraction

All protocol-specific logic is isolated behind **`InboxProviderInterface`** (`class/InboxProviderInterface.php`). The AJAX layer is provider-agnostic: every endpoint calls `InboxProviderFactory::create($account)` and works exclusively through the interface.

```
InboxProviderInterface
        │
        ├── ImapProvider        (wraps IMAPClient / Horde IMAP)
        └── WhatsAppProvider    (Meta Graph API + local DB)
        (future: GraphProvider, GmailProvider, SmsProvider…)
```

**Adding a new provider:**
1. Create `class/MyProvider.php` implementing `InboxProviderInterface`.
2. Add a `case 'myprovider':` in `InboxProviderFactory::create()`.
3. Insert accounts with `provider_type = 'myprovider'` and a `config` JSON blob.

### IMAP library

The IMAP provider uses **`bytestream/horde-imap-client`** (v2.34) instead of the PHP `imap` extension. This library communicates directly over a TCP/TLS socket, supports IMAP4rev1, and implements `CONDSTORE`/`QRESYNC` for efficient delta synchronisation.

### XOAUTH2

When `auth_type = 'oauth2'`, `IMAPClient::connect()` calls `resolveOAuthToken()`, which:

1. Extracts the `keyforprovider` suffix from `oauth_service` (e.g. `GOOGLE-work` → `keyforprovider = 'work'`).
2. Loads the stored token from `llx_oauth_token` via `DoliStorage`.
3. Refreshes the access token if expired (preserving the refresh token).
4. Returns a `Horde_Imap_Client_Password_Xoauth2` object passed to the Horde client as `xoauth2_token`.

### Sending messages

- **IMAP accounts**: outgoing emails are sent through Dolibarr's **`CMailFile`** class (SMTP). The account's SMTP credentials are temporarily injected into `$conf->global` for the duration of the send call. A copy is appended to the Sent folder via IMAP `APPEND`.
- **WhatsApp accounts**: `send_email.php` detects `provider_type = 'whatsapp'`, bypasses SMTP entirely, and calls `WhatsAppProvider::sendTextMessage()`, which posts to `/{phone_number_id}/messages` on the Graph API and stores the outgoing message in `llx_inbox_whatsapp_message`.

### WhatsApp webhook

`ajax/whatsapp_webhook.php` is a `NOLOGIN` endpoint (no Dolibarr session required). It handles:

- **GET** — Meta's subscription verification handshake: returns `hub.challenge` if `hub.verify_token` matches the value in the account's `config` JSON.
- **POST** — incoming message and status-update events. All message types are supported (`text`, `image`, `document`, `audio`, `video`, `sticker`, `location`). Status updates (`delivered`, `read`, `failed`) are propagated to `llx_inbox_whatsapp_message.status`.

### Read/unread tracking

- **IMAP**: messages are fetched with `peek => true`. When the user opens a message, a fire-and-forget AJAX call sends an IMAP `STORE +FLAGS \Seen` command.
- **WhatsApp**: `markSeen()` updates `status = 'read'` in the local DB **and** sends a read receipt to Meta (`POST /{phone_number_id}/messages` with `status: read`).

### Remote image blocking

Before injecting HTML into the sandboxed iframe, JavaScript replaces `src="https://..."` attributes with `data-original-src`. Clicking **Show images** restores the original `src` attributes via DOM manipulation inside the iframe (permitted by `allow-same-origin` in the sandbox).

---

## File structure

```
inbox/
├── admin/
│   ├── setup.php                  # Account management + global settings
│   └── tags.php                   # Tag management
├── ajax/
│   ├── get_folders.php            # List folders / virtual inbox
│   ├── get_emails.php             # Paginated message list (flat or threaded)
│   ├── get_email_body.php         # Full message body + attachments list
│   ├── get_attachment.php         # Download a specific attachment
│   ├── mark_seen.php              # Mark message read / unread
│   ├── trash_email.php            # Move to trash / delete
│   ├── send_email.php             # Send via SMTP (email) or Graph API (WhatsApp)
│   ├── whatsapp_webhook.php       # Meta webhook: verification + incoming events
│   ├── get_tags.php               # List all available tags
│   ├── get_message_tags.php       # Tags for a given message
│   ├── add_message_tag.php        # Assign a tag to a message
│   ├── remove_message_tag.php     # Remove a tag from a message
│   ├── get_unread_counts.php      # Unread count per account
│   ├── get_comments.php           # Internal comments for a message
│   ├── add_comment.php            # Post an internal comment
│   └── delete_comment.php         # Delete an internal comment
├── class/
│   ├── InboxProviderInterface.php # Contract for all messaging providers
│   ├── InboxProviderFactory.php   # Instantiate the right provider for an account
│   ├── ImapProvider.php           # IMAP/SMTP provider (wraps IMAPClient)
│   ├── WhatsAppProvider.php       # WhatsApp Business Cloud API provider
│   ├── imapclient.class.php       # Horde IMAP wrapper + OAuth2 token resolution
│   ├── inboxaccount.class.php     # Account ORM (fetch / create)
│   ├── inboxemail.class.php       # Cached email metadata
│   ├── inboxtag.class.php         # Tag ORM
│   ├── inboxmessagetag.class.php  # Tag-message association ORM
│   └── inboxcomment.class.php     # Internal comment ORM
├── core/modules/
│   └── modInbox.class.php         # Module descriptor (menus, permissions, tables)
├── css/
│   └── inbox.css                  # UI styles
├── js/
│   └── app.js                     # Single-page frontend logic
├── langs/
│   ├── en_US/inbox.lang           # English translations
│   └── fr_FR/inbox.lang           # French translations
├── lib/
│   └── inbox.lib.php              # Admin tab helper
├── sql/
│   ├── llx_inbox_account.sql                  # Account table (initial)
│   ├── llx_inbox_tag.sql                      # Tag table
│   ├── llx_inbox_message_tag.sql              # Tag-message table
│   ├── llx_inbox_email.sql                    # Cached email table
│   ├── llx_inbox_comment.sql                  # Comment table
│   ├── llx_inbox_account-alter.sql            # Migration: auth_type / oauth_service
│   ├── alter_inbox_account_add_provider_type.sql  # Migration: provider_type column
│   ├── alter_inbox_account_add_config.sql         # Migration: config JSON column
│   └── create_inbox_whatsapp_message.sql          # WhatsApp message store
├── vendor/                        # Composer dependencies (not committed)
├── composer.json
├── index.php                      # Main messaging page
├── README.md
└── README-FR.md
```

---

## License

This module is distributed under the **GNU General Public License v3 (GPL-3.0)**, the same license as Dolibarr itself.
