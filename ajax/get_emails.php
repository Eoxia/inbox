<?php
/**
 *	\file       ajax/get_emails.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to retrieve emails
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

require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT .'/custom/inbox/class/imapclient.class.php';

global $db, $user;

// Security check
if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

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
	$folder,
	$account->auth_type,
	$account->oauth_service
);

if (!$connected) {
	print json_encode(array('error' => 'IMAP Connection failed: ' . $client->error));
	exit;
}

$limit_nb = $account->sync_limit_nb ? $account->sync_limit_nb : 500;
$limit_days = $account->sync_limit_days ? $account->sync_limit_days : 180;
$offset = max(0, (int) GETPOST('offset', 'int'));
$page_size = 50;

$result = $client->getMessages($limit_nb, $limit_days, $offset, $page_size);

if ($result === false) {
	print json_encode(array('error' => 'Failed to fetch messages: ' . $client->error));
	$client->close();
	exit;
}

$client->close();

print json_encode(array(
	'success' => true,
	'account' => $account->email,
	'total' => $result['total'],
	'has_more' => $result['has_more'],
	'count' => count($result['messages']),
	'data' => $result['messages']
));
