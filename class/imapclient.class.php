<?php
/**
 *	\file       class/imapclient.class.php
 *	\ingroup    inbox
 *	\brief      IMAP client using Horde_Imap_Client_Socket (bytestream/horde-imap-client).
 *              Supports QRESYNC/CONDSTORE via getSyncToken() + getDelta().
 */

/**
 * Handles IMAP connections and mailbox operations.
 *
 * Replaces the previous ext/imap wrapper with Horde_Imap_Client_Socket,
 * which communicates directly over a socket (no PHP IMAP extension required)
 * and supports CONDSTORE/QRESYNC for efficient delta synchronisation.
 *
 * Public API is backward-compatible with the previous IMAPClient class.
 * Two new methods are added for QRESYNC:
 *   getSyncToken()  — returns an opaque token representing the current state
 *   getDelta($token, $knownUids)  — returns changes since the token was issued
 */
class IMAPClient
{
	/** @var Horde_Imap_Client_Socket|null  Active IMAP session */
	private $client = null;

	/** @var Horde_Imap_Client_Mailbox  Currently selected mailbox */
	private $mailbox;

	/** @var string  Last error message */
	public $error = '';

	/** @var string[]  Accumulated errors (kept for backward compatibility) */
	public $errors = [];

	/** @var string  Kept for backward compatibility — unused with Horde */
	public $conn_string_base = '';

	/**
	 * Open an IMAP session.
	 *
	 * @param string $host      IMAP hostname or IP
	 * @param int    $port      IMAP port (143, 993, …)
	 * @param string $security  'ssl', 'starttls'/'tls', or 'none'
	 * @param string $login     Username
	 * @param string $password  Password
	 * @param string $folder    Mailbox to select (default: 'INBOX')
	 * @return bool             True on success
	 */
	public function connect($host, $port, $security, $login, $password, $folder = 'INBOX')
	{
		$autoload = __DIR__.'/../vendor/autoload.php';
		if (!file_exists($autoload)) {
			$this->error = 'Horde autoloader not found — run composer install in the inbox module directory';
			return false;
		}
		require_once $autoload;

		$secure = false;
		if ($security === 'ssl') {
			$secure = 'ssl';
		} elseif ($security === 'starttls' || $security === 'tls') {
			$secure = 'tls';
		}

		try {
			$this->client = new Horde_Imap_Client_Socket([
				'hostspec' => $host,
				'port'     => (int) $port,
				'secure'   => $secure,
				'username' => $login,
				'password' => $password,
			]);
			$this->client->login();
			$this->mailbox = new Horde_Imap_Client_Mailbox($folder);
			$this->client->openMailbox($this->mailbox);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			$this->client = null;
			return false;
		}
	}

	/**
	 * Return a paginated list of message headers from the selected folder.
	 *
	 * Messages are sorted newest-first. Deleted messages (\Deleted) are excluded.
	 *
	 * @param int $limit_nb    Maximum number of messages to consider (pool size)
	 * @param int $limit_days  Only include messages newer than this many days
	 * @param int $offset      Zero-based offset for pagination
	 * @param int $page_size   Number of messages per page
	 * @return array|false     ['messages' => stdClass[], 'total' => int, 'has_more' => bool]
	 *                         Each message: uid, message_id, subject, from, to, cc,
	 *                         date, seen, answered, deleted, keywords
	 */
	public function getMessages($limit_nb = 500, $limit_days = 180, $offset = 0, $page_size = 50)
	{
		if (!$this->client) {
			$this->error = 'Not connected';
			return false;
		}

		try {
			$since = new DateTime('-'.$limit_days.' days');

			$query = new Horde_Imap_Client_Search_Query();
			$query->dateSearch($since, Horde_Imap_Client_Search_Query::DATE_SINCE);
			$query->flag('\Deleted', false);

			$results = $this->client->search($this->mailbox, $query, [
				'results' => [Horde_Imap_Client::SEARCH_RESULTS_MATCH],
				'sort'    => [Horde_Imap_Client::SORT_REVERSE, Horde_Imap_Client::SORT_DATE],
			]);

			$allIds = $results['match']->ids;
			if (count($allIds) > $limit_nb) {
				$allIds = array_slice($allIds, 0, $limit_nb);
			}

			$total    = count($allIds);
			$has_more = ($offset + $page_size) < $total;
			$pageIds  = array_slice($allIds, $offset, $page_size);

			if (empty($pageIds)) {
				return ['messages' => [], 'total' => $total, 'has_more' => $has_more];
			}

			$fetchQuery = new Horde_Imap_Client_Fetch_Query();
			$fetchQuery->envelope();
			$fetchQuery->flags();
			$fetchQuery->uid();

			$fetchResult = $this->client->fetch($this->mailbox, $fetchQuery, [
				'ids' => new Horde_Imap_Client_Ids($pageIds),
			]);

			$messages     = [];
			$systemFlags  = ['\Seen', '\Answered', '\Deleted', '\Flagged', '\Draft', '\Recent'];

			foreach ($fetchResult as $data) {
				$envelope = $data->getEnvelope();
				$flags    = $data->getFlags();
				$uid      = $data->getUid();

				$item             = new stdClass();
				$item->uid        = $uid;
				$item->message_id = $envelope->message_id ? trim($envelope->message_id) : '';
				$item->subject    = $envelope->subject ?: '(No Subject)';
				$item->date       = $envelope->date ? $envelope->date->format('Y-m-d H:i:s') : '';
				$item->seen       = in_array('\Seen',     $flags) ? 1 : 0;
				$item->answered   = in_array('\Answered', $flags) ? 1 : 0;
				$item->deleted    = 0;

				$item->from = $this->formatAddress($envelope->from);
				$item->to   = $this->formatAddress($envelope->to);
				$item->cc   = $this->formatAddress($envelope->cc);

				$keywords      = array_diff($flags, $systemFlags);
				$item->keywords = implode(' ', $keywords);

				$messages[] = $item;
			}

			usort($messages, static function ($a, $b) { return $b->uid - $a->uid; });

			return ['messages' => $messages, 'total' => $total, 'has_more' => $has_more];

		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Return the decoded body of a message, preferring HTML over plain text.
	 *
	 * @param int $uid  Message UID
	 * @return string   HTML body or plain text converted to HTML; empty on failure
	 */
	public function getMessageBody($uid)
	{
		if (!$this->client) return '';

		try {
			$structure = $this->fetchStructure($uid);
			if (!$structure) return '';

			$htmlId  = $structure->findBody('html');
			$plainId = $structure->findBody('plain');
			$bodyId  = $htmlId ?: $plainId;

			if (!$bodyId) return '';

			$fq = new Horde_Imap_Client_Fetch_Query();
			$fq->bodyPart($bodyId, ['decode' => true, 'peek' => true]);

			$res = $this->client->fetch($this->mailbox, $fq, [
				'ids' => new Horde_Imap_Client_Ids([$uid]),
			]);
			if (!count($res)) return '';

			$content = (string) $res->first()->getBodyPart($bodyId);

			$part    = $structure->getPart($bodyId);
			$charset = $part ? $part->getCharset() : 'UTF-8';
			if ($charset && strtolower($charset) !== 'utf-8') {
				$content = (string) @mb_convert_encoding($content, 'UTF-8', $charset);
			}

			if (!$htmlId && $plainId) {
				$content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
			}

			return $content;

		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return '';
		}
	}

	/**
	 * Return attachment descriptors for a message.
	 *
	 * @param int $uid  Message UID
	 * @return array    Each element: ['partno', 'filename', 'mime', 'size', 'encoding']
	 *                  'encoding' is always 0 — Horde handles decoding transparently
	 */
	public function getAttachments($uid)
	{
		if (!$this->client) return [];

		try {
			$structure = $this->fetchStructure($uid);
			if (!$structure) return [];

			$attachments = [];
			$map = $structure->contentTypeMap();

			foreach ($map as $mimeId => $contentType) {
				if ($mimeId === '0') continue;
				$part = $structure->getPart($mimeId);
				if (!$part) continue;

				$filename    = $part->getName(true);
				if (!$filename) continue;

				$disposition = strtolower((string) $part->getDisposition());
				if ($disposition === 'inline'
					&& in_array($contentType, ['text/plain', 'text/html'])) {
					continue;
				}

				$attachments[] = [
					'partno'   => (string) $mimeId,
					'filename' => $filename,
					'mime'     => $contentType,
					'size'     => (int) $part->getBytes(),
					'encoding' => 0,
				];
			}

			return $attachments;

		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return [];
		}
	}

	/**
	 * Fetch and return the decoded bytes of a single MIME part.
	 *
	 * The $encoding parameter is kept for backward compatibility but is ignored —
	 * Horde decodes the transfer encoding (base64/qp) automatically.
	 *
	 * @param int    $uid      Message UID
	 * @param string $partno   Dotted MIME part number, e.g. "2" or "1.2"
	 * @param int    $encoding Ignored (kept for BC)
	 * @return string          Decoded binary data, or empty string on failure
	 */
	public function getAttachmentData($uid, $partno, $encoding)
	{
		if (!$this->client) return '';

		try {
			$fq = new Horde_Imap_Client_Fetch_Query();
			$fq->bodyPart($partno, ['decode' => true, 'peek' => true]);

			$res = $this->client->fetch($this->mailbox, $fq, [
				'ids' => new Horde_Imap_Client_Ids([$uid]),
			]);
			if (!count($res)) return '';

			return (string) $res->first()->getBodyPart($partno);

		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return '';
		}
	}

	/**
	 * Return all folders for the current account, sorted by conventional order.
	 *
	 * Uses SPECIAL-USE attributes (RFC 6154) when available, falls back to
	 * name heuristics. Folder names are already UTF-8 decoded by Horde.
	 *
	 * @return array  Each element: ['id' => string, 'name' => string, 'type' => string]
	 */
	public function getFolders()
	{
		if (!$this->client) return [];

		try {
			$list = $this->client->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, [
				'attributes'  => true,
				'special_use' => true,
			]);

			$folders = [];
			foreach ($list as $name => $data) {
				$attrs = isset($data['attributes']) ? $data['attributes'] : [];
				if (in_array('\Noselect', $attrs) || in_array('\NonExistent', $attrs)) {
					continue;
				}

				$name        = (string) $name;
				$displayName = $name;
				if (stripos($displayName, 'INBOX.') === 0) {
					$displayName = substr($displayName, 6);
				}

				$type       = 'folder';
				$specialUse = isset($data['special_use']) ? $data['special_use'] : [];

				foreach ($specialUse as $use) {
					switch (strtolower($use)) {
						case '\sent':    $type = 'sent';    break;
						case '\drafts':  $type = 'drafts';  break;
						case '\trash':   $type = 'trash';   break;
						case '\junk':    $type = 'spam';    break;
						case '\archive': $type = 'archive'; break;
					}
				}

				if ($type === 'folder') {
					$lower = strtolower($name);
					if ($lower === 'inbox')                                                       $type = 'inbox';
					elseif (strpos($lower, 'sent')     !== false || strpos($lower, 'envoy')     !== false) $type = 'sent';
					elseif (strpos($lower, 'draft')    !== false || strpos($lower, 'brouillon') !== false) $type = 'drafts';
					elseif (strpos($lower, 'trash')    !== false || strpos($lower, 'corbeille') !== false) $type = 'trash';
					elseif (strpos($lower, 'spam')     !== false || strpos($lower, 'junk')      !== false
					      || strpos($lower, 'pourriel') !== false)                                $type = 'spam';
					elseif (strpos($lower, 'archive')  !== false)                                $type = 'archive';
				}

				$lower = strtolower($displayName);
				if     ($lower === 'inbox')                        $displayName = 'Boîte de réception';
				elseif ($lower === 'sent')                         $displayName = 'Envoyés';
				elseif ($lower === 'drafts')                       $displayName = 'Brouillons';
				elseif ($lower === 'trash')                        $displayName = 'Corbeille';
				elseif ($lower === 'spam' || $lower === 'junk')    $displayName = 'Pourriel';
				elseif ($lower === 'archive')                      $displayName = 'Archivé';

				if ($lower === 'inbox' || $type === 'inbox') {
					$displayName = 'Boîte de réception';
					$type        = 'inbox';
				}

				$folders[] = ['id' => $name, 'name' => $displayName, 'type' => $type];
			}

			usort($folders, static function ($a, $b) {
				$order = ['inbox' => 1, 'sent' => 2, 'drafts' => 3, 'archive' => 4, 'spam' => 5, 'trash' => 6, 'folder' => 10];
				$wa = isset($order[$a['type']]) ? $order[$a['type']] : 10;
				$wb = isset($order[$b['type']]) ? $order[$b['type']] : 10;
				return ($wa === $wb) ? strcasecmp($a['name'], $b['name']) : ($wa - $wb);
			});

			return $folders;

		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return [];
		}
	}

	/**
	 * Move a message to another folder.
	 *
	 * @param int    $uid         Message UID
	 * @param string $dest_folder Destination folder name (UTF-8)
	 * @return bool               True on success
	 */
	public function moveMessage($uid, $dest_folder)
	{
		if (!$this->client) return false;

		try {
			$this->client->copy($this->mailbox, new Horde_Imap_Client_Mailbox($dest_folder), [
				'ids'  => new Horde_Imap_Client_Ids([$uid]),
				'move' => true,
			]);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Permanently delete a message (mark \Deleted + expunge).
	 *
	 * @param int $uid  Message UID
	 * @return bool     True on success
	 */
	public function deleteMessage($uid)
	{
		if (!$this->client) return false;

		try {
			$this->client->store($this->mailbox, [
				'ids' => new Horde_Imap_Client_Ids([$uid]),
				'add' => ['\Deleted'],
			]);
			$this->client->expunge($this->mailbox);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Append a raw MIME message to a folder (used to save a copy in Sent).
	 *
	 * @param string $folder   Destination folder name (UTF-8)
	 * @param string $message  Complete raw MIME message
	 * @return bool            True on success
	 */
	public function appendMessage($folder, $message)
	{
		if (!$this->client) return false;

		try {
			$this->client->append(new Horde_Imap_Client_Mailbox($folder), [
				['data' => $message, 'flags' => ['\Seen']],
			]);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Set a user-defined keyword flag on a message.
	 *
	 * @param int    $uid      Message UID
	 * @param string $keyword  ASCII keyword without spaces
	 * @return bool            True on success
	 */
	public function setKeyword($uid, $keyword)
	{
		if (!$this->client) return false;

		try {
			$this->client->store($this->mailbox, [
				'ids' => new Horde_Imap_Client_Ids([$uid]),
				'add' => [$keyword],
			]);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			return false;
		}
	}

	/**
	 * Clear a user-defined keyword flag from a message.
	 *
	 * @param int    $uid      Message UID
	 * @param string $keyword  Keyword to remove
	 * @return bool            True on success
	 */
	public function clearKeyword($uid, $keyword)
	{
		if (!$this->client) return false;

		try {
			$this->client->store($this->mailbox, [
				'ids'    => new Horde_Imap_Client_Ids([$uid]),
				'remove' => [$keyword],
			]);
			return true;
		} catch (Horde_Imap_Client_Exception $e) {
			return false;
		}
	}

	/**
	 * Close the IMAP session.
	 *
	 * @return void
	 */
	public function close()
	{
		if ($this->client) {
			try { $this->client->logout(); } catch (Exception $e) {}
			$this->client = null;
		}
	}

	// ── QRESYNC ──────────────────────────────────────────────────────────────

	/**
	 * Return an opaque sync token encoding the current mailbox state
	 * (UIDVALIDITY, HIGHESTMODSEQ, UIDNEXT, message count).
	 *
	 * Store this token client-side (JS localStorage or DB) and pass it to
	 * getDelta() on the next refresh to receive only the changes.
	 *
	 * Returns null if the server does not support CONDSTORE.
	 *
	 * @return string|null  Opaque base64 token, or null on failure
	 */
	public function getSyncToken()
	{
		if (!$this->client) return null;

		try {
			return $this->client->getSyncToken($this->mailbox);
		} catch (Horde_Imap_Client_Exception $e) {
			return null;
		}
	}

	/**
	 * Return the delta since the given sync token (QRESYNC/CONDSTORE).
	 *
	 * On UIDVALIDITY change (folder recreated), returns ['full_resync' => true]
	 * and the caller must discard local state and reload from scratch.
	 *
	 * Returns null if the server doesn't support CONDSTORE or the token is bad.
	 *
	 * @param string $token      Token previously obtained from getSyncToken()
	 * @param int[]  $knownUids  Optional: UIDs known to the client; enables VANISHED
	 *                           detection even without server-side QRESYNC support
	 * @return array|null [
	 *   'full_resync' => bool,
	 *   'newmsgs'     => int[],   UIDs of new messages (empty if none)
	 *   'changed'     => int[],   UIDs whose flags changed
	 *   'vanished'    => int[],   UIDs that disappeared (requires QRESYNC or $knownUids)
	 *   'token'       => string,  Updated token to store for next call
	 * ]
	 */
	public function getDelta($token, $knownUids = [])
	{
		if (!$this->client || !$token) return null;

		try {
			$opts = [
				'criteria' => Horde_Imap_Client::SYNC_NEWMSGSUIDS
					| Horde_Imap_Client::SYNC_FLAGSUIDS
					| Horde_Imap_Client::SYNC_VANISHEDUIDS,
			];
			if (!empty($knownUids)) {
				$opts['ids'] = new Horde_Imap_Client_Ids($knownUids);
			}

			$sync = $this->client->sync($this->mailbox, $token, $opts);

			return [
				'full_resync' => false,
				'newmsgs'     => ($sync->newmsgs  && $sync->newmsgsuids)  ? $sync->newmsgsuids->ids  : [],
				'changed'     => ($sync->flags    && $sync->flagsuids)    ? $sync->flagsuids->ids    : [],
				'vanished'    => ($sync->vanished && $sync->vanisheduids) ? $sync->vanisheduids->ids : [],
				'token'       => $this->getSyncToken(),
			];

		} catch (Horde_Imap_Client_Exception_Sync $e) {
			if ($e->getCode() === Horde_Imap_Client_Exception_Sync::UIDVALIDITY_CHANGED) {
				return ['full_resync' => true, 'token' => $this->getSyncToken()];
			}
			return null;
		} catch (Horde_Imap_Client_Exception $e) {
			return null;
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Fetch the MIME structure for a message.
	 *
	 * @param int $uid
	 * @return Horde_Mime_Part|null
	 */
	private function fetchStructure($uid)
	{
		$fq = new Horde_Imap_Client_Fetch_Query();
		$fq->structure();

		$res = $this->client->fetch($this->mailbox, $fq, [
			'ids' => new Horde_Imap_Client_Ids([$uid]),
		]);

		if (!count($res)) return null;
		return $res->first()->getStructure();
	}

	/**
	 * Format the first address from a Horde_Mail_Rfc822_List as a string.
	 *
	 * @param Horde_Mail_Rfc822_List|null $list
	 * @return string  "Name <email>" or "email", or empty string
	 */
	private function formatAddress($list)
	{
		if (!$list) return '';

		foreach ($list as $addr) {
			return $addr->personal
				? $addr->personal . ' <' . $addr->bare_address . '>'
				: $addr->bare_address;
		}

		return '';
	}
}
