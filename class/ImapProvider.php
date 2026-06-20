<?php
/**
 * \file    class/ImapProvider.php
 * \ingroup inbox
 * \brief   InboxProviderInterface implementation for IMAP/SMTP accounts.
 *
 * Thin adapter that delegates every call to the existing IMAPClient.
 * IMAP UIDs are integers internally; this class accepts string message IDs
 * (as required by the interface) and casts them to int before forwarding.
 */

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/InboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/imapclient.class.php';

/**
 * IMAP provider — wraps IMAPClient to satisfy InboxProviderInterface.
 */
class ImapProvider implements InboxProviderInterface
{
	/** @var IMAPClient */
	private $client;

	public function __construct()
	{
		$this->client = new IMAPClient();
	}

	// ── Connection lifecycle ──────────────────────────────────────────────────

	public function connect(InboxAccount $account, $folder = 'INBOX')
	{
		return $this->client->connect(
			$account->imap_server,
			$account->imap_port,
			$account->imap_security,
			$account->imap_login,
			$account->imap_password,
			$folder,
			$account->auth_type,
			$account->oauth_service
		);
	}

	public function close()
	{
		$this->client->close();
	}

	public function getError()
	{
		return $this->client->error;
	}

	// ── Folder navigation ─────────────────────────────────────────────────────

	public function getFolders()
	{
		return $this->client->getFolders();
	}

	public function getUnseenCount($folder = 'INBOX')
	{
		return $this->client->getUnseenCount($folder);
	}

	// ── Message listing ───────────────────────────────────────────────────────

	public function getMessages($limitNb, $limitDays, $offset, $pageSize)
	{
		return $this->client->getMessages($limitNb, $limitDays, $offset, $pageSize);
	}

	public function getThreadedMessages($limitDays, $offset, $pageSize)
	{
		return $this->client->getThreadedMessages($limitDays, $offset, $pageSize);
	}

	// ── Message detail ────────────────────────────────────────────────────────

	public function getMessageBody($messageId)
	{
		return $this->client->getMessageBody((int) $messageId);
	}

	public function getAttachments($messageId)
	{
		$result = $this->client->getAttachments((int) $messageId);
		return $result ?: [];
	}

	public function getAttachmentData($messageId, $partNo, $encoding)
	{
		return $this->client->getAttachmentData((int) $messageId, $partNo, $encoding);
	}

	// ── Message actions ───────────────────────────────────────────────────────

	public function markSeen($messageId)
	{
		return $this->client->markSeen((int) $messageId);
	}

	public function markUnseen($messageId)
	{
		return $this->client->markUnseen((int) $messageId);
	}

	public function moveMessage($messageId, $targetFolder)
	{
		return $this->client->moveMessage((int) $messageId, $targetFolder);
	}

	public function deleteMessage($messageId)
	{
		return $this->client->deleteMessage((int) $messageId);
	}

	// ── Provider capabilities ─────────────────────────────────────────────────

	public function getType()
	{
		return 'imap';
	}

	public function supportsFolders()
	{
		return true;
	}

	public function supportsCompose()
	{
		return true;
	}

	public function supportsThreads()
	{
		return true;
	}

	public function appendMessage($folder, $message)
	{
		return $this->client->appendMessage($folder, $message);
	}

	public function setKeyword($messageId, $keyword)
	{
		return $this->client->setKeyword((int) $messageId, $keyword);
	}

	public function clearKeyword($messageId, $keyword)
	{
		return $this->client->clearKeyword((int) $messageId, $keyword);
	}

	// ── IMAP-specific extensions (not in interface) ───────────────────────────

	/**
	 * Return a QRESYNC token representing the current mailbox state.
	 * Only available on IMAP servers supporting CONDSTORE/QRESYNC.
	 *
	 * @return mixed  Opaque token, or false if not supported
	 */
	public function getSyncToken()
	{
		return $this->client->getSyncToken();
	}

	/**
	 * Return changes since a previously obtained sync token.
	 *
	 * @param  mixed $token       Token from getSyncToken()
	 * @param  array $knownUids   UIDs already known to the client
	 * @return array              ['new' => int[], 'changed' => int[], 'deleted' => int[]]
	 */
	public function getDelta($token, $knownUids = [])
	{
		return $this->client->getDelta($token, $knownUids);
	}
}
