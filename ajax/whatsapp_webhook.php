<?php
/**
 * \file    ajax/whatsapp_webhook.php
 * \ingroup inbox
 * \brief   Meta WhatsApp Business Cloud API webhook.
 *
 * GET  — verification handshake (Meta calls this when you first configure the webhook)
 * POST — incoming message / status-update events
 *
 * Configure in Meta Developer Console:
 *   Callback URL : https://your-host/custom/inbox/ajax/whatsapp_webhook.php?account_id=N
 *   Verify Token : value stored in account config.verify_token
 *   Subscriptions: messages
 */

if (!defined('NOCSRFCHECK'))  define('NOCSRFCHECK', '1');
if (!defined('NOREQUIRELOG')) define('NOREQUIRELOG', '1');
if (!defined('NOLOGIN'))      define('NOLOGIN', '1');

$res = @include '../../main.inc.php';
if (!$res) $res = @include '../../../main.inc.php';
if (!$res) { http_response_code(500); exit; }

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxaccount.class.php';

global $db;

$account_id = (int) GETPOST('account_id', 'int');
if ($account_id <= 0) { http_response_code(400); exit; }

$account = new InboxAccount($db);
if ($account->fetch($account_id) <= 0) { http_response_code(404); exit; }

$config       = $account->getConfig();
$verify_token = isset($config['verify_token']) ? $config['verify_token'] : '';

// ── GET: webhook verification ─────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	// PHP converts dots to underscores in $_GET keys, but parse_str() on the
	// raw query string preserves them — use that for reliable access.
	$qparams = [];
	parse_str($_SERVER['QUERY_STRING'] ?? '', $qparams);

	$mode      = isset($qparams['hub.mode'])         ? $qparams['hub.mode']         : '';
	$token     = isset($qparams['hub.verify_token'])  ? $qparams['hub.verify_token']  : '';
	$challenge = isset($qparams['hub.challenge'])     ? $qparams['hub.challenge']     : '';

	if ($mode === 'subscribe' && $token === $verify_token && $challenge !== '') {
		http_response_code(200);
		echo (string) $challenge;
	} else {
		http_response_code(403);
	}
	exit;
}

// ── POST: incoming events ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (empty($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
	http_response_code(400);
	exit;
}

foreach ((array) ($payload['entry'] ?? []) as $entry) {
	foreach ((array) ($entry['changes'] ?? []) as $change) {
		if (($change['field'] ?? '') !== 'messages') continue;

		$value = $change['value'] ?? [];

		// Build name → phone map from contacts array
		$nameByPhone = [];
		foreach ((array) ($value['contacts'] ?? []) as $contact) {
			$phone = $contact['wa_id'] ?? '';
			$name  = $contact['profile']['name'] ?? '';
			if ($phone !== '') $nameByPhone[$phone] = $name;
		}

		// ── Incoming messages ─────────────────────────────────────────────────
		foreach ((array) ($value['messages'] ?? []) as $msg) {
			$wamid     = $msg['id']        ?? '';
			$fromPhone = $msg['from']      ?? '';
			$fromName  = $nameByPhone[$fromPhone] ?? '';
			$timestamp = (int) ($msg['timestamp'] ?? dol_now());
			$msgType   = $msg['type']      ?? 'text';
			$body      = '';
			$mediaId   = null;
			$mediaMime = null;
			$mediaName = null;

			switch ($msgType) {
				case 'text':
					$body = $msg['text']['body'] ?? '';
					break;
				case 'image':
					$mediaId   = $msg['image']['id']        ?? null;
					$mediaMime = $msg['image']['mime_type'] ?? 'image/jpeg';
					$body      = $msg['image']['caption']   ?? '';
					break;
				case 'document':
					$mediaId   = $msg['document']['id']        ?? null;
					$mediaMime = $msg['document']['mime_type'] ?? 'application/octet-stream';
					$mediaName = $msg['document']['filename']  ?? null;
					$body      = $msg['document']['caption']   ?? '';
					break;
				case 'audio':
					$mediaId   = $msg['audio']['id']        ?? null;
					$mediaMime = $msg['audio']['mime_type'] ?? 'audio/ogg';
					break;
				case 'video':
					$mediaId   = $msg['video']['id']        ?? null;
					$mediaMime = $msg['video']['mime_type'] ?? 'video/mp4';
					$body      = $msg['video']['caption']   ?? '';
					break;
				case 'sticker':
					$mediaId   = $msg['sticker']['id'] ?? null;
					$mediaMime = 'image/webp';
					break;
				case 'location':
					$lat  = $msg['location']['latitude']  ?? '';
					$lng  = $msg['location']['longitude'] ?? '';
					$name = $msg['location']['name']      ?? '';
					$body = $name !== '' ? $name.' ('.$lat.', '.$lng.')' : $lat.', '.$lng;
					break;
				default:
					$body = '('.$msgType.')';
			}

			if ($wamid === '' || $fromPhone === '') continue;

			$sql  = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."inbox_whatsapp_message";
			$sql .= " (fk_account, wamid, direction, from_phone, from_name, to_phone,";
			$sql .= "  msg_type, body, media_id, media_mime, media_name,";
			$sql .= "  status, date_message, date_creation) VALUES (";
			$sql .= (int) $account_id.",";
			$sql .= "'".$db->escape($wamid)."',";
			$sql .= "0,";
			$sql .= "'".$db->escape($fromPhone)."',";
			$sql .= "'".$db->escape($fromName)."',";
			$sql .= "'',";
			$sql .= "'".$db->escape($msgType)."',";
			$sql .= "'".$db->escape((string) $body)."',";
			$sql .= ($mediaId   !== null ? "'".$db->escape($mediaId)."'"   : 'NULL').",";
			$sql .= ($mediaMime !== null ? "'".$db->escape($mediaMime)."'" : 'NULL').",";
			$sql .= ($mediaName !== null ? "'".$db->escape($mediaName)."'" : 'NULL').",";
			$sql .= "'received',";
			$sql .= "'".$db->idate($timestamp)."',";
			$sql .= "'".$db->idate(dol_now())."'";
			$sql .= ")";

			$db->query($sql);
		}

		// ── Status updates (delivered, read, failed) ──────────────────────────
		foreach ((array) ($value['statuses'] ?? []) as $status) {
			$wamid     = $status['id']     ?? '';
			$newStatus = $status['status'] ?? '';

			if ($wamid === '' || !in_array($newStatus, ['delivered', 'read', 'failed'])) continue;

			$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_whatsapp_message";
			$sql .= " SET status='".$db->escape($newStatus)."'";
			$sql .= " WHERE fk_account=".(int) $account_id;
			$sql .= " AND wamid='".$db->escape($wamid)."'";
			$db->query($sql);
		}
	}
}

http_response_code(200);
echo '{"status":"ok"}';
exit;
