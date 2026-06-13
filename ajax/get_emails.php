<?php
/**
 *	\file       ajax/get_emails.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to retrieve emails
 */

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

require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/imapclient.class.php';

global $db, $user;

// Security check
if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

// Find first active account for the user or shared
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);

if (!$resql || $db->num_rows($resql) == 0) {
	// Fallback: try to find ANY active account if none specific to user (for initial testing)
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

$limit_nb = $account->sync_limit_nb ? $account->sync_limit_nb : 500;
$limit_days = $account->sync_limit_days ? $account->sync_limit_days : 180;

$messages = $client->getMessages($limit_nb, $limit_days);

if ($messages === false) {
	print json_encode(array('error' => 'Failed to fetch messages: ' . $client->error));
	$client->close();
	exit;
}

$client->close();

print json_encode(array(
	'success' => true,
	'account' => $account->email,
	'limit_nb' => $limit_nb,
	'limit_days' => $limit_days,
	'count' => count($messages),
	'data' => $messages
));
