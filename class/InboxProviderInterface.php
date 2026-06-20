<?php
/**
 * \file    class/InboxProviderInterface.php
 * \ingroup inbox
 * \brief   Contract for all messaging providers (IMAP, Graph, Gmail, WhatsApp, SMS…)
 *
 * Every provider must implement this interface so that the AJAX layer and the
 * rest of the module can remain agnostic of the underlying protocol.
 *
 * Message identifiers are always passed as strings.  For IMAP, the string is
 * the numeric UID cast to string.  For REST-based providers (Graph, Gmail,
 * WhatsApp…) it is the opaque ID returned by the remote API.
 */
interface InboxProviderInterface
{
	// ── Connection lifecycle ──────────────────────────────────────────────────

	/**
	 * Open a session for the given account.
	 *
	 * For IMAP, $folder selects the initial mailbox.
	 * For chat providers that do not have folders, $folder is ignored.
	 *
	 * @param  InboxAccount $account  Account to connect to
	 * @param  string       $folder   Initial folder / mailbox (default: 'INBOX')
	 * @return bool                   True on success
	 */
	public function connect(InboxAccount $account, $folder = 'INBOX');

	/**
	 * Close the connection and release any resources.
	 */
	public function close();

	/**
	 * Return the last error message.
	 *
	 * @return string
	 */
	public function getError();

	// ── Folder / conversation navigation ─────────────────────────────────────

	/**
	 * Return the folder list (email providers) or active conversation list (chat providers).
	 *
	 * Each item must contain at least:
	 *   - 'name'   (string)  — internal identifier used in subsequent calls
	 *   - 'label'  (string)  — display name
	 *   - 'unseen' (int)     — number of unread messages (0 if unknown)
	 *
	 * @return array
	 */
	public function getFolders();

	/**
	 * Return the number of unseen messages in the given folder.
	 *
	 * @param  string $folder  Folder name; ignored by chat providers
	 * @return int
	 */
	public function getUnseenCount($folder = 'INBOX');

	// ── Message listing ───────────────────────────────────────────────────────

	/**
	 * Return a paginated flat list of messages, newest first.
	 *
	 * @param  int        $limitNb    Maximum pool size (cap on total messages considered)
	 * @param  int        $limitDays  Only include messages newer than N days
	 * @param  int        $offset     Zero-based pagination offset
	 * @param  int        $pageSize   Number of items per page
	 * @return array|false            ['messages' => array, 'total' => int, 'has_more' => bool]
	 *                                or false on error
	 */
	public function getMessages($limitNb, $limitDays, $offset, $pageSize);

	/**
	 * Return a paginated list of messages grouped by thread / conversation.
	 *
	 * @param  int        $limitDays  Only include messages newer than N days
	 * @param  int        $offset     Zero-based pagination offset
	 * @param  int        $pageSize   Number of items per page
	 * @return array|false            ['messages' => array, 'total' => int, 'has_more' => bool]
	 *                                or false on error
	 */
	public function getThreadedMessages($limitDays, $offset, $pageSize);

	// ── Message detail ────────────────────────────────────────────────────────

	/**
	 * Return the body of a single message.
	 *
	 * @param  string     $messageId  Provider-specific message identifier
	 * @return array|false            ['html' => string, 'plain' => string] or false on error
	 */
	public function getMessageBody($messageId);

	/**
	 * Return the attachment list for a message.
	 *
	 * Each item: ['partno' => string, 'name' => string, 'mime' => string, 'size' => int]
	 *
	 * @param  string $messageId
	 * @return array
	 */
	public function getAttachments($messageId);

	/**
	 * Return the raw binary content of one attachment part.
	 *
	 * @param  string     $messageId  Message identifier
	 * @param  string     $partNo     Part number / attachment identifier (provider-specific)
	 * @param  int        $encoding   Transfer-encoding constant (IMAP-style; REST providers ignore it)
	 * @return string|false
	 */
	public function getAttachmentData($messageId, $partNo, $encoding);

	// ── Message actions ───────────────────────────────────────────────────────

	/**
	 * Mark a message as read.
	 *
	 * @param  string $messageId
	 * @return bool
	 */
	public function markSeen($messageId);

	/**
	 * Mark a message as unread.
	 *
	 * @param  string $messageId
	 * @return bool
	 */
	public function markUnseen($messageId);

	/**
	 * Move a message to another folder.
	 *
	 * Returns false (without error) for providers that do not support folders.
	 *
	 * @param  string $messageId
	 * @param  string $targetFolder
	 * @return bool
	 */
	public function moveMessage($messageId, $targetFolder);

	/**
	 * Permanently delete a message.
	 *
	 * @param  string $messageId
	 * @return bool
	 */
	public function deleteMessage($messageId);

	// ── Extended actions (best-effort — providers that do not support these return false) ──

	/**
	 * Append a raw RFC-822 message to a folder (e.g. save a sent copy).
	 *
	 * Returns false for providers that do not support folder appending.
	 *
	 * @param  string $folder   Target folder name
	 * @param  string $message  Raw RFC-822 message
	 * @return bool
	 */
	public function appendMessage($folder, $message);

	/**
	 * Set a custom keyword / flag on a message (IMAP custom flags).
	 *
	 * Returns false silently for providers that do not support keywords.
	 *
	 * @param  string $messageId
	 * @param  string $keyword
	 * @return bool
	 */
	public function setKeyword($messageId, $keyword);

	/**
	 * Clear a custom keyword / flag from a message.
	 *
	 * Returns false silently for providers that do not support keywords.
	 *
	 * @param  string $messageId
	 * @param  string $keyword
	 * @return bool
	 */
	public function clearKeyword($messageId, $keyword);

	// ── Provider capabilities ─────────────────────────────────────────────────

	/**
	 * Return the provider type identifier.
	 *
	 * Known values: 'imap', 'graph', 'gmail', 'whatsapp', 'sms', 'telegram'
	 *
	 * @return string
	 */
	public function getType();

	/**
	 * Whether the provider organises messages into named folders.
	 *
	 * True for email providers (IMAP, Graph, Gmail).
	 * False for chat providers (WhatsApp, SMS, Telegram…).
	 *
	 * @return bool
	 */
	public function supportsFolders();

	/**
	 * Whether the provider allows sending free-form outgoing messages.
	 *
	 * False for WhatsApp on first contact (a Meta-approved template is required).
	 *
	 * @return bool
	 */
	public function supportsCompose();

	/**
	 * Whether the provider supports grouping messages by thread / conversation.
	 *
	 * @return bool
	 */
	public function supportsThreads();
}
