<?php
/**
 *	\file       ajax/get_email_body.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to retrieve a specific email body
 */
if (!defined('NOTOKENRENEWAL')) {
	// Disables token renewal
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
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

ini_set('display_errors', '0');
error_reporting(0);

require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/imapclient.class.php';

global $db, $user;

// Security check
if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$uid = (int) GETPOST('uid', 'int');
if (empty($uid)) {
	print json_encode(array('error' => 'Missing message UID'));
	exit;
}

// Find first active account for the user or shared
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);

if (!$resql || $db->num_rows($resql) == 0) {
	// Fallback
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 ORDER BY rowid ASC LIMIT 1";
	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) == 0) {
		print json_encode(array('error' => 'No active mailbox configured.'));
		exit;
	}
}

$obj = $db->fetch_object($resql);

$account = new InboxAccount($db);
$account->fetch($obj->rowid);

$folder = GETPOST('folder', 'restricthtml');
if (empty($folder)) {
	$folder = 'INBOX';
}

$client = new IMAPClient();
$connected = $client->connect(
	$account->imap_server,
	$account->imap_port,
	$account->imap_security,
	$account->imap_login,
	$account->imap_password,
	$folder
);

if (!$connected) {
	print json_encode(array('error' => 'IMAP Connection failed: ' . $client->error));
	exit;
}

$body        = $client->getMessageBody($uid);
$attachments = $client->getAttachments($uid);

$client->close();

$json = json_encode(array(
	'success'      => true,
	'body'         => $body,
	'attachments'  => $attachments,
	'block_images' => (bool) getDolGlobalInt('INBOX_BLOCK_REMOTE_IMAGES', 1),
), JSON_INVALID_UTF8_SUBSTITUTE);

if (!$json) {
	$json = json_encode(array('error' => 'JSON encode failed: ' . json_last_error_msg()));
}

print $json;
