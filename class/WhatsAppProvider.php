<?php
/**
 * \file    class/WhatsAppProvider.php
 * \ingroup inbox
 * \brief   InboxProviderInterface implementation for WhatsApp Business Cloud API.
 *
 * Messages are stored locally in llx_inbox_whatsapp_message (populated by
 * ajax/whatsapp_webhook.php when Meta delivers incoming events). Outgoing
 * messages are sent via Meta's Graph API REST endpoint.
 *
 * Required account config (stored as JSON in llx_inbox_account.config):
 *   {
 *     "phone_number_id": "...",   // Meta phone number ID
 *     "access_token":    "...",   // System user or permanent token
 *     "verify_token":    "...",   // Secret for webhook verification
 *     "api_version":     "v20.0"  // optional, default v20.0
 *   }
 */

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/InboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

class WhatsAppProvider implements InboxProviderInterface
{
	const GRAPH_BASE = 'https://graph.facebook.com';

	/** @var string */
	private $phoneNumberId = '';
	/** @var string */
	private $accessToken = '';
	/** @var string */
	private $apiVersion = 'v20.0';
	/** @var int */
	private $accountId = 0;
	/** @var string */
	private $error = '';

	// ── Connection lifecycle ──────────────────────────────────────────────────

	public function connect(InboxAccount $account, $folder = 'INBOX')
	{
		$config = $account->getConfig();

		$this->phoneNumberId = isset($config['phone_number_id']) ? $config['phone_number_id'] : '';
		$this->accessToken   = isset($config['access_token'])   ? $config['access_token']   : $account->imap_password;
		$this->apiVersion    = isset($config['api_version'])    ? $config['api_version']    : 'v20.0';
		$this->accountId     = (int) $account->rowid;

		if (empty($this->phoneNumberId) || empty($this->accessToken)) {
			$this->error = 'Missing phone_number_id or access_token in account config';
			return false;
		}

		return true;
	}

	public function close()
	{
		// Stateless REST provider — nothing to close
	}

	public function getError()
	{
		return $this->error;
	}

	// ── Folder navigation ─────────────────────────────────────────────────────

	public function getFolders()
	{
		return [
			[
				'id'     => 'INBOX',
				'name'   => 'INBOX',
				'label'  => 'WhatsApp',
				'type'   => 'inbox',
				'unseen' => $this->getUnseenCount(),
			],
		];
	}

	public function getUnseenCount($folder = 'INBOX')
	{
		global $db;

		$sql  = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account = ".(int) $this->accountId." AND status = 'received'";
		$res  = $db->query($sql);
		if (!$res) return 0;
		return (int) $db->fetch_object($res)->cnt;
	}

	// ── Message listing ───────────────────────────────────────────────────────

	public function getMessages($limitNb, $limitDays, $offset, $pageSize)
	{
		global $db;

		$since = date('Y-m-d', dol_now() - ((int) $limitDays * 86400));

		$sqlCount  = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sqlCount .= " WHERE fk_account = ".(int) $this->accountId;
		$sqlCount .= " AND status != 'deleted'";
		$sqlCount .= " AND date_message >= '".$db->escape($since)."'";

		$resCount = $db->query($sqlCount);
		if (!$resCount) {
			$this->error = $db->lasterror();
			return false;
		}
		$total = min((int) $db->fetch_object($resCount)->cnt, (int) $limitNb);

		$has_more = ($offset + $pageSize) < $total;

		$sql  = "SELECT rowid, wamid, direction, from_phone, from_name, to_phone,";
		$sql .= " msg_type, body, media_id, media_name, status, date_message";
		$sql .= " FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account = ".(int) $this->accountId;
		$sql .= " AND status != 'deleted'";
		$sql .= " AND date_message >= '".$db->escape($since)."'";
		$sql .= " ORDER BY date_message DESC";
		$sql .= " LIMIT ".(int) $offset.",".(int) $pageSize;

		$res = $db->query($sql);
		if (!$res) {
			$this->error = $db->lasterror();
			return false;
		}

		$messages = [];
		while ($obj = $db->fetch_object($res)) {
			$messages[] = $this->rowToMessage($obj);
		}

		return ['messages' => $messages, 'total' => $total, 'has_more' => $has_more];
	}

	public function getThreadedMessages($limitDays, $offset, $pageSize)
	{
		global $db;

		$since = date('Y-m-d', dol_now() - ((int) $limitDays * 86400));

		// Count distinct conversation partners
		$sqlCount  = "SELECT COUNT(DISTINCT IF(direction=0, from_phone, to_phone)) as cnt";
		$sqlCount .= " FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sqlCount .= " WHERE fk_account = ".(int) $this->accountId;
		$sqlCount .= " AND status != 'deleted'";
		$sqlCount .= " AND date_message >= '".$db->escape($since)."'";

		$resCount = $db->query($sqlCount);
		if (!$resCount) {
			$this->error = $db->lasterror();
			return false;
		}
		$total    = (int) $db->fetch_object($resCount)->cnt;
		$has_more = ($offset + $pageSize) < $total;

		// Latest message + stats per conversation partner
		$sql  = "SELECT";
		$sql .= "  IF(direction=0, from_phone, to_phone) AS partner_phone,";
		$sql .= "  MAX(IF(direction=0 AND from_name != '', from_name, '')) AS partner_name,";
		$sql .= "  MAX(date_message) AS latest_date,";
		$sql .= "  SUM(CASE WHEN status='received' THEN 1 ELSE 0 END) AS unseen_count,";
		$sql .= "  COUNT(*) AS msg_count,";
		$sql .= "  MAX(rowid) AS latest_rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account = ".(int) $this->accountId;
		$sql .= " AND status != 'deleted'";
		$sql .= " AND date_message >= '".$db->escape($since)."'";
		$sql .= " GROUP BY IF(direction=0, from_phone, to_phone)";
		$sql .= " ORDER BY latest_date DESC";
		$sql .= " LIMIT ".(int) $offset.",".(int) $pageSize;

		$res = $db->query($sql);
		if (!$res) {
			$this->error = $db->lasterror();
			return false;
		}

		$partners = [];
		while ($obj = $db->fetch_object($res)) {
			$partners[] = $obj;
		}
		if (empty($partners)) {
			return ['messages' => [], 'total' => $total, 'has_more' => $has_more];
		}

		// Fetch latest message details for each partner in one query
		$latestIds = array_map(function ($p) { return (int) $p->latest_rowid; }, $partners);
		$sql2  = "SELECT rowid, wamid, direction, from_phone, from_name, to_phone,";
		$sql2 .= " msg_type, body, media_id, media_name, status, date_message";
		$sql2 .= " FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql2 .= " WHERE rowid IN (".implode(',', $latestIds).")";
		$res2 = $db->query($sql2);

		$latestByRowid = [];
		if ($res2) {
			while ($obj = $db->fetch_object($res2)) {
				$latestByRowid[(int) $obj->rowid] = $obj;
			}
		}

		$threads = [];
		foreach ($partners as $p) {
			$latestRow = isset($latestByRowid[(int) $p->latest_rowid]) ? $latestByRowid[(int) $p->latest_rowid] : null;
			if (!$latestRow) continue;

			$latestMsg = $this->rowToMessage($latestRow);

			$thread               = new stdClass();
			$thread->uid          = $latestMsg->uid;
			$thread->is_thread    = true;
			$thread->message_id   = $latestMsg->message_id;
			$thread->subject      = $latestMsg->subject;
			$thread->date         = $latestMsg->date;
			$thread->to           = $latestMsg->to;
			$thread->from         = $p->partner_name
				? $p->partner_name.' <+'.$p->partner_phone.'>'
				: '+'.$p->partner_phone;
			$thread->seen         = ((int) $p->unseen_count === 0) ? 1 : 0;
			$thread->answered     = 0;
			$thread->unseen_count = (int) $p->unseen_count;
			$thread->count        = (int) $p->msg_count;
			$thread->participants = [$thread->from];
			$thread->messages     = [$latestMsg];
			$thread->keywords     = '';

			$threads[] = $thread;
		}

		return ['messages' => $threads, 'total' => $total, 'has_more' => $has_more];
	}

	// ── Message detail ────────────────────────────────────────────────────────

	public function getMessageBody($messageId)
	{
		global $db;

		$sql  = "SELECT rowid, wamid, direction, from_phone, from_name, to_phone,";
		$sql .= " msg_type, body, media_id, media_mime, media_name, status, date_message";
		$sql .= " FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account = ".(int) $this->accountId;
		$sql .= " AND (wamid = '".$db->escape($messageId)."' OR rowid = ".(int) $messageId.")";
		$sql .= " LIMIT 1";

		$res = $db->query($sql);
		if (!$res || !($obj = $db->fetch_object($res))) {
			$this->error = 'Message not found: '.$messageId;
			return false;
		}

		$body = htmlspecialchars((string) $obj->body, ENT_QUOTES, 'UTF-8');

		if ($obj->msg_type !== 'text' && !empty($obj->media_name)) {
			$icons = ['image' => '📷 ', 'audio' => '🎵 ', 'video' => '🎥 ', 'sticker' => '🎭 '];
			$icon  = isset($icons[$obj->msg_type]) ? $icons[$obj->msg_type] : '📎 ';
			$attachment = '<em>'.$icon.htmlspecialchars($obj->media_name, ENT_QUOTES, 'UTF-8').'</em>';
			$body = $attachment.($body ? '<br><br>'.$body : '');
		}

		$contact = (int) $obj->direction === 0
			? ('+'.$obj->from_phone.($obj->from_name ? ' ('.$obj->from_name.')' : ''))
			: '+'.$obj->to_phone;
		$direction = (int) $obj->direction === 0 ? 'From: ' : 'To: ';

		$html  = '<small style="color:#888">'.$direction.htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
		$html .= ' &mdash; '.htmlspecialchars($obj->date_message, ENT_QUOTES, 'UTF-8').'</small>';
		$html .= '<br><br>'.nl2br($body);

		return ['html' => $html, 'plain' => strip_tags($body)];
	}

	public function getAttachments($messageId)
	{
		global $db;

		$sql  = "SELECT media_id, media_mime, media_name FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account = ".(int) $this->accountId;
		$sql .= " AND (wamid = '".$db->escape($messageId)."' OR rowid = ".(int) $messageId.")";
		$sql .= " AND media_id IS NOT NULL LIMIT 1";

		$res = $db->query($sql);
		if (!$res || !($obj = $db->fetch_object($res)) || empty($obj->media_id)) {
			return [];
		}

		return [[
			'partno' => $obj->media_id,
			'name'   => $obj->media_name ?: 'attachment',
			'mime'   => $obj->media_mime ?: 'application/octet-stream',
			'size'   => 0,
		]];
	}

	public function getAttachmentData($messageId, $partNo, $encoding)
	{
		// $partNo is the Meta media_id — first fetch the download URL, then download
		$urlData = $this->apiGet('/'.$partNo);
		if (!$urlData || empty($urlData['url'])) {
			$this->error = 'Cannot retrieve media URL for '.$partNo;
			return false;
		}

		return $this->apiGetRaw($urlData['url']);
	}

	// ── Message actions ───────────────────────────────────────────────────────

	public function markSeen($messageId)
	{
		global $db;

		$wamid = $this->resolveWamid($messageId, $db);
		if (!$wamid) return false;

		$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_whatsapp_message SET status='read'";
		$sql .= " WHERE fk_account=".(int) $this->accountId." AND wamid='".$db->escape($wamid)."'";
		$db->query($sql);

		// Send read receipt to Meta (best-effort)
		$this->apiPost('/'.$this->phoneNumberId.'/messages', [
			'messaging_product' => 'whatsapp',
			'status'            => 'read',
			'message_id'        => $wamid,
		]);

		return true;
	}

	public function markUnseen($messageId)
	{
		global $db;

		$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_whatsapp_message SET status='received'";
		$sql .= " WHERE fk_account=".(int) $this->accountId;
		$sql .= " AND (wamid='".$db->escape($messageId)."' OR rowid=".(int) $messageId.")";
		return (bool) $db->query($sql);
	}

	public function moveMessage($messageId, $targetFolder)
	{
		return false;
	}

	public function deleteMessage($messageId)
	{
		global $db;

		$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_whatsapp_message SET status='deleted'";
		$sql .= " WHERE fk_account=".(int) $this->accountId;
		$sql .= " AND (wamid='".$db->escape($messageId)."' OR rowid=".(int) $messageId.")";
		return (bool) $db->query($sql);
	}

	// ── Extended actions ──────────────────────────────────────────────────────

	public function appendMessage($folder, $message)
	{
		return false;
	}

	public function setKeyword($messageId, $keyword)
	{
		return false;
	}

	public function clearKeyword($messageId, $keyword)
	{
		return false;
	}

	// ── Provider capabilities ─────────────────────────────────────────────────

	public function getType()
	{
		return 'whatsapp';
	}

	public function supportsFolders()
	{
		return false;
	}

	public function supportsCompose()
	{
		return true;
	}

	public function supportsThreads()
	{
		return true;
	}

	// ── WhatsApp-specific: outgoing message ───────────────────────────────────

	/**
	 * Send a WhatsApp text message to a recipient.
	 *
	 * @param  string $to    Recipient phone in international format (digits only, no +)
	 * @param  string $body  Plain-text message body
	 * @return bool
	 */
	public function sendTextMessage($to, $body)
	{
		global $db;

		$to = preg_replace('/[^0-9]/', '', $to);

		$result = $this->apiPost('/'.$this->phoneNumberId.'/messages', [
			'messaging_product' => 'whatsapp',
			'to'                => $to,
			'type'              => 'text',
			'text'              => ['body' => $body],
		]);

		if (!$result || empty($result['messages'][0]['id'])) {
			return false;
		}

		$wamid = $result['messages'][0]['id'];

		$sql  = "INSERT INTO ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " (fk_account, wamid, direction, from_phone, from_name, to_phone,";
		$sql .= "  msg_type, body, status, date_message, date_creation) VALUES (";
		$sql .= (int) $this->accountId.",";
		$sql .= "'".$db->escape($wamid)."',";
		$sql .= "1,'','','".$db->escape($to)."','text',";
		$sql .= "'".$db->escape($body)."','sent',";
		$sql .= "'".$db->idate(dol_now())."','".$db->idate(dol_now())."')";

		return (bool) $db->query($sql);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Map a DB row to a stdClass matching the IMAP-style message format.
	 */
	private function rowToMessage($obj)
	{
		$item            = new stdClass();
		$item->uid       = $obj->wamid;
		$item->message_id = $obj->wamid;
		$item->seen      = in_array($obj->status, ['read', 'sent', 'delivered']) ? 1 : 0;
		$item->answered  = 0;
		$item->deleted   = 0;
		$item->keywords  = '';
		$item->date      = $obj->date_message;
		$item->cc        = '';

		if ((int) $obj->direction === 0) {
			$item->from = $obj->from_name ? $obj->from_name.' <+'.$obj->from_phone.'>' : '+'.$obj->from_phone;
			$item->to   = 'me';
		} else {
			$item->from = 'me';
			$item->to   = '+'.$obj->to_phone;
		}

		if ($obj->msg_type === 'text' || empty($obj->msg_type)) {
			$item->subject = mb_substr((string) $obj->body, 0, 80) ?: '(message)';
		} else {
			$labels = [
				'image'    => '(image)',
				'audio'    => '(audio)',
				'video'    => '(video)',
				'sticker'  => '(sticker)',
				'location' => '(location)',
				'document' => '(doc: '.($obj->media_name ?: 'file').')',
			];
			$item->subject = isset($labels[$obj->msg_type]) ? $labels[$obj->msg_type] : '('.$obj->msg_type.')';
		}

		$item->has_attachments = !empty($obj->media_id) ? 1 : 0;

		return $item;
	}

	/**
	 * Resolve a wamid string from either a wamid or a local rowid.
	 */
	private function resolveWamid($messageId, $db)
	{
		// wamid strings contain letters (e.g. "wamid.ABC...")
		if (preg_match('/[a-zA-Z]/', $messageId)) return $messageId;

		$sql  = "SELECT wamid FROM ".MAIN_DB_PREFIX."inbox_whatsapp_message";
		$sql .= " WHERE fk_account=".(int) $this->accountId." AND rowid=".(int) $messageId." LIMIT 1";
		$res  = $db->query($sql);
		if (!$res || !($row = $db->fetch_object($res))) return false;
		return $row->wamid;
	}

	/**
	 * GET request to the Meta Graph API. Returns decoded JSON or false.
	 */
	private function apiGet($path)
	{
		$url    = self::GRAPH_BASE.'/'.$this->apiVersion.$path;
		$result = getURLContent($url, 'GET', '', 1, ['Authorization: Bearer '.$this->accessToken]);
		if (empty($result['content'])) {
			$this->error = 'Meta API GET failed: '.($result['curl_error'] ?? 'empty response');
			return false;
		}
		$data = json_decode($result['content'], true);
		if (!empty($data['error'])) {
			$this->error = $data['error']['message'] ?? 'Unknown Meta API error';
			return false;
		}
		return $data;
	}

	/**
	 * POST request to the Meta Graph API. Returns decoded JSON or false.
	 */
	private function apiPost($path, $payload)
	{
		$url    = self::GRAPH_BASE.'/'.$this->apiVersion.$path;
		$result = getURLContent($url, 'POST', json_encode($payload), 1, [
			'Authorization: Bearer '.$this->accessToken,
			'Content-Type: application/json',
		]);
		if (empty($result['content'])) {
			$this->error = 'Meta API POST failed: '.($result['curl_error'] ?? 'empty response');
			return false;
		}
		$data = json_decode($result['content'], true);
		if (!empty($data['error'])) {
			$this->error = $data['error']['message'] ?? 'Unknown Meta API error';
			return false;
		}
		return $data;
	}

	/**
	 * Download raw binary content from a URL (for media files).
	 */
	private function apiGetRaw($url)
	{
		$result = getURLContent($url, 'GET', '', 1, ['Authorization: Bearer '.$this->accessToken]);
		if (empty($result['content'])) {
			$this->error = 'Media download failed';
			return false;
		}
		return $result['content'];
	}
}
