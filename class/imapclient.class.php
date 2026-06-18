<?php
/**
 *	\file       class/imapclient.class.php
 *	\ingroup    inbox
 *	\brief      Thin wrapper around the PHP IMAP extension for the Inbox module
 */

/**
 * Handles IMAP connections and all mailbox operations: listing folders,
 * fetching message headers/bodies/attachments, moving and deleting messages.
 *
 * All message references use IMAP UIDs (not sequence numbers) so that
 * imap_expunge() calls never invalidate open references.
 */
class IMAPClient
{
	/** @var resource|false  Active IMAP stream returned by imap_open() */
	private $mbox;

	/** @var string  Last error message set by any method */
	public $error;

	/** @var string[]  Accumulated error strings (not currently used externally) */
	public $errors = array();

	/** @var string  Connection string without folder suffix, e.g. "{mail.example.com:993/imap/ssl}" */
	public $conn_string_base = '';

	/**
	 * Open an IMAP stream to the given server and folder.
	 *
	 * @param string $host      IMAP server hostname or IP
	 * @param int    $port      IMAP port (143, 993, …)
	 * @param string $security  Transport security: 'ssl', 'starttls'/'tls', or 'none'
	 * @param string $login     Account username
	 * @param string $password  Account password
	 * @param string $folder    Mailbox folder to open (default: 'INBOX')
	 * @return bool             True on success, false on failure ($this->error is set)
	 */
	public function connect($host, $port, $security, $login, $password, $folder = 'INBOX')
	{
		if (!function_exists('imap_open')) {
			$this->error = "PHP IMAP extension is not installed";
			return false;
		}

		$conn_string = "{" . $host . ":" . $port . "/imap";

		if ($security == 'ssl') {
			$conn_string .= "/ssl";
		} elseif ($security == 'starttls' || $security == 'tls') {
			$conn_string .= "/tls";
		}

		// Add /novalidate-cert for typical dev environments, though not ideal for strict prod
		// $conn_string .= "/novalidate-cert";
		$conn_string .= "}";
		$this->conn_string_base = $conn_string;

		if (strtoupper($folder) != 'INBOX') {
			$folder = imap_utf7_encode($folder);
		}

		$conn_string .= $folder;

		imap_errors();

		$this->mbox = @imap_open($conn_string, $login, $password);

		if (!$this->mbox) {
			$errors = imap_errors();
			$this->error = "Failed to connect to IMAP server. " . ($errors ? implode(', ', $errors) : "Unknown error");
			return false;
		}

		return true;
	}

	/**
	 * Return a paginated list of message headers from the current folder.
	 *
	 * Uses SE_UID / FT_UID throughout so UIDs are stable across expunge operations.
	 * Messages are sorted newest-first by UID before pagination is applied.
	 *
	 * @param int $limit_nb    Maximum total messages to consider (pool size)
	 * @param int $limit_days  Only consider messages newer than this many days
	 * @param int $offset      Zero-based index of the first message to return
	 * @param int $page_size   Number of messages per page
	 * @return array|false     ['messages' => stdClass[], 'total' => int, 'has_more' => bool]
	 *                         or false if not connected.
	 *                         Each message object has: uid, message_id, subject, from, to, cc,
	 *                         date, seen, answered, deleted.
	 */
	public function getMessages($limit_nb = 500, $limit_days = 180, $offset = 0, $page_size = 50)
	{
		if (!$this->mbox) {
			$this->error = "Not connected";
			return false;
		}

		$date_since = date("d-M-Y", strtotime("-".$limit_days." days"));
		// SE_UID: return UIDs instead of sequence numbers — UIDs never change after expunge
		$emails = imap_search($this->mbox, 'SINCE "'.$date_since.'"', SE_UID);

		if (!$emails) {
			return array('messages' => array(), 'total' => 0, 'has_more' => false);
		}

		rsort($emails);
		$total = count($emails);

		$page_emails = array_slice($emails, $offset, $page_size);
		$has_more = ($offset + $page_size) < $total;

		$result = array();

		if (!empty($page_emails)) {
			$sequence = implode(',', $page_emails);
			// FT_UID: sequence is UIDs
			$overviews = imap_fetch_overview($this->mbox, $sequence, FT_UID);

			if ($overviews) {
				foreach ($overviews as $overview) {
					$item = new stdClass();
					$item->uid        = $overview->uid;
					$item->message_id = isset($overview->message_id) ? trim($overview->message_id) : '';

					$subject = isset($overview->subject) ? $overview->subject : '(No Subject)';
					$item->subject = $this->decodeMimeHeader($subject);

					$from = isset($overview->from) ? $overview->from : '';
					$item->from = $this->decodeMimeHeader($from);

					$to = isset($overview->to) ? $overview->to : '';
					$item->to = $this->decodeMimeHeader($to);

					$cc = isset($overview->cc) ? $overview->cc : '';
					$item->cc = $this->decodeMimeHeader($cc);

					$item->date = isset($overview->date) ? date("Y-m-d H:i:s", strtotime($overview->date)) : '';
					$item->seen     = (isset($overview->seen)     && $overview->seen)     ? 1 : 0;
					$item->answered = (isset($overview->answered) && $overview->answered) ? 1 : 0;
					$item->deleted  = (isset($overview->deleted)  && $overview->deleted)  ? 1 : 0;

					// User-defined IMAP keywords (space-separated), e.g. "Urgent Projet"
					$item->keywords = isset($overview->keywords) ? trim($overview->keywords) : '';

					$result[] = $item;
				}
			}

			// Higher UID = newer message
			usort($result, function ($a, $b) { return $b->uid - $a->uid; });
		}

		return array('messages' => $result, 'total' => $total, 'has_more' => $has_more);
	}

	/**
	 * Decode an encoded MIME header value (e.g. =?UTF-8?B?...?=) to a UTF-8 string.
	 *
	 * @param string $string  Raw header value
	 * @return string         UTF-8 decoded string
	 */
	private function decodeMimeHeader($string)
	{
		$decoded = '';
		$elements = imap_mime_header_decode($string);
		if (is_array($elements)) {
			foreach ($elements as $element) {
				$charset = $element->charset;
				$text = $element->text;
				if ($charset != 'default' && strtolower($charset) != 'utf-8' && strtolower($charset) != 'us-ascii') {
					$text = mb_convert_encoding($text, 'UTF-8', $charset);
				}
				$decoded .= $text;
			}
		} else {
			$decoded = $string;
		}
		return $decoded;
	}

	/**
	 * Return the list of attachments for a message.
	 *
	 * Walks the MIME tree recursively. Inline text/plain and text/html parts
	 * are excluded even when they carry a filename.
	 *
	 * @param int $uid  Message UID
	 * @return array    Each element: ['partno' => string, 'filename' => string,
	 *                  'mime' => string, 'size' => int, 'encoding' => int]
	 */
	public function getAttachments($uid)
	{
		if (!$this->mbox) return array();
		$structure = @imap_fetchstructure($this->mbox, $uid, FT_UID);
		$attachments = array();
		$this->findAttachments($structure, $attachments, '');
		return $attachments;
	}

	/**
	 * Recursive MIME-tree walker that collects attachment descriptors.
	 *
	 * @param object $structure   imap_fetchstructure() part object
	 * @param array  &$attachments Accumulator array (passed by reference)
	 * @param string $partno      Dotted MIME part number, e.g. "1.2" (empty for root)
	 * @return void
	 */
	private function findAttachments($structure, &$attachments, $partno)
	{
		if (!$structure) return;

		if ($structure->type == 1) { // MULTIPART
			foreach ($structure->parts as $index => $subStruct) {
				$sub = $partno ? $partno.'.'.($index + 1) : (string)($index + 1);
				$this->findAttachments($subStruct, $attachments, $sub);
			}
			return;
		}

		$filename = '';
		if (!empty($structure->dparameters)) {
			foreach ($structure->dparameters as $p) {
				if (strtolower($p->attribute) == 'filename') {
					$filename = $this->decodeMimeHeader($p->value);
					break;
				}
			}
		}
		if (empty($filename) && !empty($structure->parameters)) {
			foreach ($structure->parameters as $p) {
				if (strtolower($p->attribute) == 'name') {
					$filename = $this->decodeMimeHeader($p->value);
					break;
				}
			}
		}

		if (empty($filename)) return;

		$disposition = isset($structure->disposition) ? strtolower($structure->disposition) : '';
		if ($disposition === 'inline' && $structure->type === 0
			&& in_array(strtolower($structure->subtype), array('html', 'plain'))) {
			return;
		}

		$attachments[] = array(
			'partno'   => $partno ?: '1',
			'filename' => $filename,
			'mime'     => $this->getMimeType($structure),
			'size'     => isset($structure->bytes) ? (int)$structure->bytes : 0,
			'encoding' => isset($structure->encoding) ? (int)$structure->encoding : 0,
		);
	}

	/**
	 * Fetch and decode the raw bytes of a single MIME part (for attachment download).
	 *
	 * @param int    $uid       Message UID
	 * @param string $partno    Dotted MIME part number, e.g. "2" or "1.2"
	 * @param int    $encoding  IMAP encoding constant (3 = BASE64, 4 = QUOTED-PRINTABLE)
	 * @return string           Decoded binary data, or empty string on failure
	 */
	public function getAttachmentData($uid, $partno, $encoding)
	{
		if (!$this->mbox) return '';
		$raw = @imap_fetchbody($this->mbox, $uid, $partno, FT_UID);
		if ($encoding == 3) return base64_decode($raw);
		if ($encoding == 4) return quoted_printable_decode($raw);
		return $raw;
	}

	/**
	 * Return the decoded body of a message, preferring HTML over plain text.
	 *
	 * Falls back to imap_body() if the MIME structure yields nothing.
	 * Plain-text bodies are converted to HTML via nl2br + htmlspecialchars.
	 *
	 * @param int $uid  Message UID
	 * @return string   HTML or plain-text body, empty string on failure
	 */
	public function getMessageBody($uid)
	{
		if (!$this->mbox) return '';

		$structure = @imap_fetchstructure($this->mbox, $uid, FT_UID);
		$body = $this->getPart($this->mbox, $uid, "TEXT/HTML", $structure);

		if (empty($body)) {
			$body = $this->getPart($this->mbox, $uid, "TEXT/PLAIN", $structure);
			if ($body) {
				$body = nl2br(htmlspecialchars($body));
			}
		}

		if (empty($body)) {
			$body = @imap_body($this->mbox, $uid, FT_UID);
		}

		return $body;
	}

	/**
	 * Recursively search the MIME tree for a part matching $mimeType.
	 *
	 * For multipart/alternative containers the first matching subpart is returned.
	 * For other multipart types the tree is traversed depth-first.
	 *
	 * @param resource $mbox        Active IMAP stream
	 * @param int      $uid         Message UID
	 * @param string   $mimeType    MIME type to find, e.g. "TEXT/HTML"
	 * @param object   $structure   imap_fetchstructure() part object
	 * @param string   $partNumber  Current dotted part number (empty at root)
	 * @return string|false         Decoded content or false if not found
	 */
	private function getPart($mbox, $uid, $mimeType, $structure, $partNumber = false)
	{
		if (!$structure) return false;

		$prefix = ($partNumber ? $partNumber."." : "");

		if ($structure->type == 1) { // MULTIPART
			foreach ($structure->parts as $index => $subStruct) {
				$partNum = $prefix.($index + 1);
				if ($structure->subtype == "ALTERNATIVE") {
					$mime = $this->getMimeType($subStruct);
					if (strtoupper($mime) == strtoupper($mimeType)) {
						return $this->decodeBody(@imap_fetchbody($mbox, $uid, $partNum, FT_UID), $subStruct->encoding, $subStruct->parameters);
					}
				}
				$data = $this->getPart($mbox, $uid, $mimeType, $subStruct, $partNum);
				if ($data) return $data;
			}
		} else {
			$mime = $this->getMimeType($structure);
			if (strtoupper($mime) == strtoupper($mimeType)) {
				$partNum = $partNumber ? $partNumber : "1";
				return $this->decodeBody(@imap_fetchbody($mbox, $uid, $partNum, FT_UID), $structure->encoding, $structure->parameters);
			}
		}
		return false;
	}

	/**
	 * Build a "TYPE/SUBTYPE" MIME string from an imap_fetchstructure() part object.
	 *
	 * @param object $structure  imap_fetchstructure() part object
	 * @return string            e.g. "TEXT/HTML", "APPLICATION/PDF"
	 */
	private function getMimeType($structure)
	{
		$primary = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");
		if ($structure->type && isset($primary[$structure->type])) {
			return $primary[$structure->type] . "/" . $structure->subtype;
		}
		return "TEXT/PLAIN";
	}

	/**
	 * Decode a raw MIME part body and convert it to UTF-8.
	 *
	 * @param string      $body        Raw bytes from imap_fetchbody()
	 * @param int         $encoding    IMAP encoding constant (3=BASE64, 4=QUOTED-PRINTABLE)
	 * @param object[]|null $parameters MIME parameters array (used to read charset)
	 * @return string                  UTF-8 decoded body
	 */
	private function decodeBody($body, $encoding, $parameters)
	{
		if ($encoding == 4) {
			$body = quoted_printable_decode($body);
		} elseif ($encoding == 3) {
			$body = base64_decode($body);
		}

		$charset = 'UTF-8';
		if ($parameters) {
			foreach ($parameters as $p) {
				if (strtolower($p->attribute) == 'charset') {
					$charset = $p->value;
					break;
				}
			}
		}

		if (strtolower($charset) != 'utf-8') {
			$body = @mb_convert_encoding($body, 'UTF-8', $charset);
		}

		return $body;
	}

	/**
	 * Return all folders/mailboxes for the current account, sorted by conventional order
	 * (Inbox → Sent → Drafts → Archive → Spam → Trash → other folders alphabetically).
	 *
	 * Folder names are decoded from IMAP modified UTF-7 to UTF-8 and translated
	 * to French for standard mailbox names (Inbox, Sent, Drafts, Trash, Spam, Archive).
	 *
	 * @return array  Each element: ['id' => string (raw IMAP name),
	 *                'name' => string (display name, UTF-8),
	 *                'type' => string (inbox|sent|drafts|trash|spam|archive|folder)]
	 */
	public function getFolders()
	{
		if (!$this->mbox) return array();

		$mailboxes = imap_getmailboxes($this->mbox, $this->conn_string_base, "*");
		$folders = array();

		if (is_array($mailboxes)) {
			foreach ($mailboxes as $mailbox) {
				$name = str_replace($this->conn_string_base, '', $mailbox->name);
				// imap_utf7_decode can return invalid UTF-8 on certain PHP/c-client versions.
				// Convert IMAP modified UTF-7 (&...-) to standard UTF-7 (+...-) then use mb_convert_encoding.
				$utf7std = preg_replace_callback('/&([^-]*)-/', function ($m) {
					return $m[1] === '' ? '&' : '+' . $m[1] . '-';
				}, $name);
				$cleanName = @mb_convert_encoding($utf7std, 'UTF-8', 'UTF-7');
				if ($cleanName === false || !mb_check_encoding($cleanName, 'UTF-8')) {
					$cleanName = mb_scrub($name);
				}

				$type = 'folder';
				$lower = strtolower($cleanName);
				if ($lower == 'inbox') $type = 'inbox';
				elseif (strpos($lower, 'sent') !== false || strpos($lower, 'envoy') !== false) $type = 'sent';
				elseif (strpos($lower, 'draft') !== false || strpos($lower, 'brouillon') !== false) $type = 'drafts';
				elseif (strpos($lower, 'trash') !== false || strpos($lower, 'corbeille') !== false) $type = 'trash';
				elseif (strpos($lower, 'spam') !== false || strpos($lower, 'junk') !== false || strpos($lower, 'pourriel') !== false) $type = 'spam';
				elseif (strpos($lower, 'archive') !== false) $type = 'archive';

				if (stripos($cleanName, 'INBOX.') === 0) {
					$cleanName = substr($cleanName, 6);
				}

				if ($type == 'inbox' && strtolower($cleanName) == 'inbox') $cleanName = 'Boîte de réception';
				elseif ($type == 'sent' && strtolower($cleanName) == 'sent') $cleanName = 'Envoyés';
				elseif ($type == 'drafts' && strtolower($cleanName) == 'drafts') $cleanName = 'Brouillons';
				elseif ($type == 'trash' && strtolower($cleanName) == 'trash') $cleanName = 'Corbeille';
				elseif ($type == 'spam' && (strtolower($cleanName) == 'spam' || strtolower($cleanName) == 'junk')) $cleanName = 'Pourriel';
				elseif ($type == 'archive' && strtolower($cleanName) == 'archive') $cleanName = 'Archivé';

				$folders[] = array(
					'id' => $name,
					'name' => $cleanName,
					'type' => $type
				);
			}

			usort($folders, function($a, $b) {
				$order = array(
					'inbox' => 1,
					'sent' => 2,
					'drafts' => 3,
					'archive' => 4,
					'spam' => 5,
					'trash' => 6,
					'folder' => 10
				);

				$weightA = isset($order[$a['type']]) ? $order[$a['type']] : 10;
				$weightB = isset($order[$b['type']]) ? $order[$b['type']] : 10;

				if ($weightA == $weightB) {
					return strcasecmp($a['name'], $b['name']);
				}
				return $weightA - $weightB;
			});
		}

		return $folders;
	}

	/**
	 * Move a message to another folder (e.g. Trash).
	 *
	 * Uses CP_UID so $uid is treated as a UID, not a sequence number.
	 * Calls imap_expunge() immediately so the source folder is cleaned up.
	 *
	 * @param int    $uid         Message UID
	 * @param string $dest_folder Destination folder name (UTF-8)
	 * @return bool               True on success, false on failure ($this->error is set)
	 */
	public function moveMessage($uid, $dest_folder)
	{
		if (!$this->mbox) return false;

		$dest = imap_utf7_encode($dest_folder);
		// CP_UID: $uid is a UID, not a sequence number
		if (!imap_mail_move($this->mbox, (string)$uid, $dest, CP_UID)) {
			$this->error = "Failed to move message: " . imap_last_error();
			return false;
		}
		imap_expunge($this->mbox);
		return true;
	}

	/**
	 * Permanently delete a message (mark \Deleted + expunge).
	 *
	 * Uses FT_UID so $uid is treated as a UID, not a sequence number.
	 *
	 * @param int $uid  Message UID
	 * @return bool     True on success, false on failure ($this->error is set)
	 */
	public function deleteMessage($uid)
	{
		if (!$this->mbox) return false;

		// FT_UID: $uid is a UID, not a sequence number
		if (!imap_delete($this->mbox, (string)$uid, FT_UID)) {
			$this->error = "Failed to delete message: " . imap_last_error();
			return false;
		}
		imap_expunge($this->mbox);
		return true;
	}

	/**
	 * Append a raw MIME message to a folder (used to save a copy in Sent).
	 *
	 * @param string $folder   Destination folder name (UTF-8)
	 * @param string $message  Complete raw MIME message string
	 * @return bool            True on success, false on failure ($this->error is set)
	 */
	public function appendMessage($folder, $message)
	{
		if (!$this->mbox) return false;

		$targetBox = $this->conn_string_base . imap_utf7_encode($folder);

		// \Seen flag sets the message as read in the Sent folder
		if (imap_append($this->mbox, $targetBox, $message, "\\Seen")) {
			return true;
		} else {
			$this->error = "Failed to append message to folder: " . imap_last_error();
			return false;
		}
	}

	/**
	 * Set a user-defined keyword flag on a message.
	 *
	 * The keyword must be a single ASCII word without spaces (IMAP RFC 3501 §2.3.2).
	 * Not all IMAP servers support user-defined keywords; failure is silently ignored.
	 *
	 * @param  int    $uid      Message UID
	 * @param  string $keyword  IMAP keyword, e.g. "Urgent"
	 * @return bool             True on success
	 */
	public function setKeyword($uid, $keyword)
	{
		if (!$this->mbox) return false;
		return (bool) imap_setflag_full($this->mbox, (string)$uid, $keyword, ST_UID);
	}

	/**
	 * Clear a user-defined keyword flag from a message.
	 *
	 * @param  int    $uid      Message UID
	 * @param  string $keyword  IMAP keyword to remove
	 * @return bool             True on success
	 */
	public function clearKeyword($uid, $keyword)
	{
		if (!$this->mbox) return false;
		return (bool) imap_clearflag_full($this->mbox, (string)$uid, $keyword, ST_UID);
	}

	/**
	 * Close the IMAP stream.
	 *
	 * @return void
	 */
	public function close()
	{
		if ($this->mbox) {
			imap_close($this->mbox);
			$this->mbox = null;
		}
	}
}
