<?php
/**
 *	\file       ajax/mark_seen.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to mark a message as seen (\Seen flag)
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
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

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/imapclient.class.php';

global $db, $user;

if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$uid    = (int) GETPOST('uid', 'int');
$folder = GETPOST('folder', 'restricthtml');

if (!$uid) {
	print json_encode(array('error' => 'Missing uid'));
	exit;
}
if (empty($folder)) {
	$folder = 'INBOX';
}

$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
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
	print json_encode(array('error' => 'IMAP Connection failed: '.$client->error));
	exit;
}

$ok = $client->markSeen($uid);
$client->close();

if (!$ok) {
	print json_encode(array('error' => $client->error));
	exit;
}

print json_encode(array('success' => true));
