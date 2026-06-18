<?php
/**
 *	\file       class/imapclient.class.php
 *	\ingroup    inbox
 *	\brief      Class to handle IMAP connections and retrieve emails
 */

class IMAPClient
{
	private $mbox;
	public $error;
	public $errors = array();

	public $conn_string_base = '';

	/**
	 * Connect to IMAP server
	 *
	 * @param string $host      IMAP server host
	 * @param int    $port      IMAP server port
	 * @param string $security  'ssl', 'starttls', 'none'
	 * @param string $login     Username
	 * @param string $password  Password
	 * @param string $folder    Folder name
	 * @return bool             True if connected, False if error
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

		// Map simple names to standard IMAP folder encoding if needed
		if (strtoupper($folder) != 'INBOX') {
			// Convert encoding to modified UTF-7 for IMAP folder names
			$folder = imap_utf7_encode($folder);
		}
dol_syslog($conn_string, LOG_NOTICE);
		$conn_string .= $folder;

		// Clear previous errors
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
	 * Retrieve a list of messages headers
	 *
	 * @param int $limit_nb    Max number of messages to fetch
	 * @param int $limit_days  Max age in days
	 * @return array|bool      Array of message objects or false on error
	 */
	public function getMessages($limit_nb = 500, $limit_days = 180, $offset = 0, $page_size = 50)
	{
		if (!$this->mbox) {
			$this->error = "Not connected";
			return false;
		}

		$date_since = date("d-M-Y", strtotime("-".$limit_days." days"));
		$emails = imap_search($this->mbox, 'SINCE "'.$date_since.'"');

		if (!$emails) {
			return array('messages' => array(), 'total' => 0, 'has_more' => false);
		}

		// Sort by newest first; limit_nb is no longer used to cap the pool
		// since pagination (offset/page_size) already controls per-request loading.
		rsort($emails);
		$total = count($emails);

		// Paginate
		$page_emails = array_slice($emails, $offset, $page_size);
		$has_more = ($offset + $page_size) < $total;

		$result = array();

		if (!empty($page_emails)) {
			$sequence = implode(',', $page_emails);
			$overviews = imap_fetch_overview($this->mbox, $sequence, 0);

			if ($overviews) {
				foreach ($overviews as $overview) {
					$item = new stdClass();
					$item->uid = $overview->uid;
					$item->msgno = $overview->msgno;

					$subject = isset($overview->subject) ? $overview->subject : '(No Subject)';
					$item->subject = $this->decodeMimeHeader($subject);

					$from = isset($overview->from) ? $overview->from : '';
					$item->from = $this->decodeMimeHeader($from);

					$item->date = isset($overview->date) ? date("Y-m-d H:i:s", strtotime($overview->date)) : '';
					$item->seen = (isset($overview->seen) && $overview->seen) ? 1 : 0;
					$item->recent = (isset($overview->recent) && $overview->recent) ? 1 : 0;
					$item->answered = (isset($overview->answered) && $overview->answered) ? 1 : 0;
					$item->deleted = (isset($overview->deleted) && $overview->deleted) ? 1 : 0;
					$item->snippet = '';

					$result[] = $item;
				}
			}

			usort($result, function($a, $b) {
				return $b->msgno - $a->msgno;
			});
		}

		return array('messages' => $result, 'total' => $total, 'has_more' => $has_more);
	}

	/**
	 * Decode MIME headers
	 * @param string $string
	 * @return string
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
	 * Return list of attachments for a message
	 * @param int $msgno
	 * @return array  Each element: ['partno', 'filename', 'mime', 'size']
	 */
	public function getAttachments($msgno)
	{
		if (!$this->mbox) return array();
		$structure = @imap_fetchstructure($this->mbox, $msgno);
		$attachments = array();
		$this->findAttachments($msgno, $structure, $attachments, '');
		return $attachments;
	}

	private function findAttachments($msgno, $structure, &$attachments, $partno)
	{
		if (!$structure) return;

		if ($structure->type == 1) { // MULTIPART
			foreach ($structure->parts as $index => $subStruct) {
				$sub = $partno ? $partno.'.'.($index + 1) : (string)($index + 1);
				$this->findAttachments($msgno, $subStruct, $attachments, $sub);
			}
			return;
		}

		// Collect filename from Content-Disposition params first, then Content-Type params
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

		// Skip inline text parts — those are the email body
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
		);
	}

	/**
	 * Fetch raw bytes for one MIME part (for attachment download)
	 * @param int    $msgno
	 * @param string $partno  e.g. "2" or "1.2"
	 * @param int    $encoding  IMAP encoding constant
	 * @return string
	 */
	public function getAttachmentData($msgno, $partno, $encoding)
	{
		if (!$this->mbox) return '';
		$raw = @imap_fetchbody($this->mbox, $msgno, $partno);
		if ($encoding == 3) return base64_decode($raw);
		if ($encoding == 4) return quoted_printable_decode($raw);
		return $raw;
	}

	/**
	 * Return encoding constant for a specific part
	 * @param int    $msgno
	 * @param string $partno
	 * @return int
	 */
	public function getPartEncoding($msgno, $partno)
	{
		$structure = @imap_fetchstructure($this->mbox, $msgno);
		return $this->findPartEncoding($structure, explode('.', $partno));
	}

	private function findPartEncoding($structure, $path)
	{
		$idx = (int)array_shift($path) - 1;
		if (!empty($path)) {
			if (isset($structure->parts[$idx])) {
				return $this->findPartEncoding($structure->parts[$idx], $path);
			}
			return 0;
		}
		if ($structure->type == 1 && isset($structure->parts[$idx])) {
			return $structure->parts[$idx]->encoding;
		}
		return $structure->encoding ?? 0;
	}

	/**
	 * Retrieve message body (HTML preferred, else plain text)
	 *
	 * @param int $msgno Message number
	 * @return string
	 */
	public function getMessageBody($msgno)
	{
		if (!$this->mbox) return '';

		$structure = @imap_fetchstructure($this->mbox, $msgno);
		$body = $this->getPart($this->mbox, $msgno, "TEXT/HTML", $structure);

		if (empty($body)) {
			$body = $this->getPart($this->mbox, $msgno, "TEXT/PLAIN", $structure);
			if ($body) {
				$body = nl2br(htmlspecialchars($body));
			}
		}

		if (empty($body)) {
			// Fallback
			$body = @imap_body($this->mbox, $msgno);
		}

		return $body;
	}

	/**
	 * Extract specific part from IMAP message
	 */
	private function getPart($mbox, $msgno, $mimeType, $structure, $partNumber = false)
	{
		if (!$structure) return false;

		$prefix = ($partNumber ? $partNumber . "." : "");

		if ($structure->type == 1) { // MULTIPART
			foreach ($structure->parts as $index => $subStruct) {
				$partNum = $prefix . ($index + 1);
				if ($structure->subtype == "ALTERNATIVE") {
					// In alternative, HTML is usually last. We check if it matches what we want.
					$mime = $this->getMimeType($subStruct);
					if (strtoupper($mime) == strtoupper($mimeType)) {
						return $this->decodeBody(@imap_fetchbody($mbox, $msgno, $partNum), $subStruct->encoding, $subStruct->parameters);
					}
				}
				$data = $this->getPart($mbox, $msgno, $mimeType, $subStruct, $partNum);
				if ($data) return $data;
			}
		} else {
			$mime = $this->getMimeType($structure);
			if (strtoupper($mime) == strtoupper($mimeType)) {
				$partNum = $partNumber ? $partNumber : "1";
				return $this->decodeBody(@imap_fetchbody($mbox, $msgno, $partNum), $structure->encoding, $structure->parameters);
			}
		}
		return false;
	}

	private function getMimeType($structure)
	{
		$primary = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");
		if ($structure->type && isset($primary[$structure->type])) {
			return $primary[$structure->type] . "/" . $structure->subtype;
		}
		return "TEXT/PLAIN";
	}

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
	 * Retrieve all folders/mailboxes
	 * @return array
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

				// Standardize icon/type mapping based on common names
				$type = 'folder';
				$lower = strtolower($cleanName);
				if ($lower == 'inbox') $type = 'inbox';
				elseif (strpos($lower, 'sent') !== false || strpos($lower, 'envoy') !== false) $type = 'sent';
				elseif (strpos($lower, 'draft') !== false || strpos($lower, 'brouillon') !== false) $type = 'drafts';
				elseif (strpos($lower, 'trash') !== false || strpos($lower, 'corbeille') !== false) $type = 'trash';
				elseif (strpos($lower, 'spam') !== false || strpos($lower, 'junk') !== false || strpos($lower, 'pourriel') !== false) $type = 'spam';
				elseif (strpos($lower, 'archive') !== false) $type = 'archive';

				// Clean display name
				// Remove "INBOX." prefix if present
				if (stripos($cleanName, 'INBOX.') === 0) {
					$cleanName = substr($cleanName, 6);
				}

				// Translate standard names
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

			// Sort folders: Inbox, Sent, Drafts, then others
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
	 * Move a message to another folder (e.g. Trash)
	 * @param int    $msgno       Message sequence number
	 * @param string $dest_folder Destination folder name (UTF-8)
	 * @return bool
	 */
	public function moveMessage($msgno, $dest_folder)
	{
		if (!$this->mbox) return false;

		$dest = imap_utf7_encode($dest_folder);
		if (!imap_mail_move($this->mbox, (string)$msgno, $dest)) {
			$this->error = "Failed to move message: " . imap_last_error();
			return false;
		}
		imap_expunge($this->mbox);
		return true;
	}

	/**
	 * Permanently delete a message (mark \Deleted + expunge)
	 * @param int $msgno Message sequence number
	 * @return bool
	 */
	public function deleteMessage($msgno)
	{
		if (!$this->mbox) return false;

		if (!imap_delete($this->mbox, (string)$msgno)) {
			$this->error = "Failed to delete message: " . imap_last_error();
			return false;
		}
		imap_expunge($this->mbox);
		return true;
	}

	/**
	 * Append a message to a specific folder (e.g. Sent folder)
	 * @param string $folder   Destination folder name
	 * @param string $message  Raw MIME message string
	 * @return bool
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
	 * Disconnect from IMAP server
	 */
	public function close()
	{
		if ($this->mbox) {
			imap_close($this->mbox);
			$this->mbox = null;
		}
	}
}
