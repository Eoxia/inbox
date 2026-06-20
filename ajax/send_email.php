<?php
/**
 *	\file       ajax/send_email.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to send an email
 */

if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
$res = 0;
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../../main.inc.php';
}
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT .'/core/class/cmailfile.class.php';
require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/inboxaccount.class.php';

global $db, $user, $conf, $langs;

// Security check
if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$to = GETPOST('to', 'alpha');
$cc = GETPOST('cc', 'alpha');
$subject = GETPOST('subject', 'alpha');
$body = GETPOST('body', 'none'); // HTML content
$in_reply_to = GETPOST('in_reply_to', 'alpha'); // UID or Message-ID

if (empty($to) || empty($subject) || empty($body)) {
	print json_encode(array('error' => 'Missing required fields (to, subject, body)'));
	exit;
}

$account_id = (int) GETPOST('account_id', 'int');
if ($account_id > 0) {
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE rowid = ".$account_id." AND status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) LIMIT 1";
} else {
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
}
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
	print json_encode(array('error' => 'No active mailbox configured.'));
	exit;
}

$obj = $db->fetch_object($resql);

$account = new InboxAccount($db);
$account->fetch($obj->rowid);

if (empty($account->smtp_server)) {
	print json_encode(array('error' => 'SMTP server not configured for this account.'));
	exit;
}

// Temporarily override Dolibarr global SMTP settings to use the user's account
$conf->global->MAIN_MAIL_SENDMODE = 'smtps';
$conf->global->MAIN_MAIL_SMTP_SERVER = $account->smtp_server;
$conf->global->MAIN_MAIL_SMTP_PORT = $account->smtp_port;
$conf->global->MAIN_MAIL_SMTPS_ID = $account->smtp_login;
$conf->global->MAIN_MAIL_SMTPS_PW = $account->smtp_password;
$conf->global->MAIN_MAIL_EMAIL_TLS = ($account->smtp_security == 'ssl' ? 1 : 0);
$conf->global->MAIN_MAIL_EMAIL_STARTTLS = ($account->smtp_security == 'starttls' ? 1 : 0);

// For In-Reply-To, we might need a real Message-ID. If we only have IMAP UID, it's not a valid Message-ID.
// In a full implementation, we'd fetch the real Message-ID from IMAP headers.
// For now, we pass it down if it looks like a Message-ID (contains @), otherwise leave empty.
$real_in_reply_to = (strpos($in_reply_to, '@') !== false) ? $in_reply_to : '';

$mailfile = new CMailFile(
	$subject,
	$to,
	$account->email, // from
	$body,
	array(), // files
	array(), // mime types
	array(), // mime names
	$cc, // CC
	'', // BCC
	0, // delivery receipt
	1, // msg is html
	$account->email, // errors to
	'', // css
	'', // trackid
	'', // more in header
	'standard', // sendcontext
	'', // replyto
	'', // upload dir
	$real_in_reply_to, // in_reply_to
	$real_in_reply_to  // references
);

$res_send = $mailfile->sendfile();

if ($res_send) {
	// -----------------------------------------------------
	// Save a copy to the IMAP "Sent" folder
	// -----------------------------------------------------
	require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/imapclient.class.php';
	
	$client = new IMAPClient();
	if ($client->connect($account->imap_server, $account->imap_port, $account->imap_security, $account->imap_login, $account->imap_password, 'INBOX', $account->auth_type, $account->oauth_service)) {
		
		$folders = $client->getFolders();
		$sentFolderId = '';
		foreach ($folders as $f) {
			if ($f['type'] == 'sent') {
				$sentFolderId = $f['id'];
				break;
			}
		}
		
		if ($sentFolderId) {
			$rawMessage = "From: " . $account->email . "\r\n";
			$rawMessage .= "To: " . $to . "\r\n";
			if (!empty($cc)) $rawMessage .= "Cc: " . $cc . "\r\n";
			$rawMessage .= "Subject: " . $subject . "\r\n";
			$rawMessage .= "Date: " . date("r") . "\r\n";
			$rawMessage .= "MIME-Version: 1.0\r\n";
			$rawMessage .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
			$rawMessage .= $body;
			
			$client->appendMessage($sentFolderId, $rawMessage);
		}
		$client->close();
	}
	// -----------------------------------------------------

	print json_encode(array('success' => true));
} else {
	print json_encode(array('error' => 'Failed to send email. Check SMTP settings. ' . $mailfile->error));
}
