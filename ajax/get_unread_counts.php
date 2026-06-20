<?php
/**
 *	\file       ajax/get_unread_counts.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint — unseen message count for INBOX of each active account
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

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/imapclient.class.php';

global $db, $user;

if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$sql = "SELECT rowid, imap_server, imap_port, imap_security, imap_login, imap_password, auth_type, oauth_service"
	." FROM ".MAIN_DB_PREFIX."inbox_account"
	." WHERE status = 1 AND (fk_user = ".((int) $user->id)." OR shared = 1)"
	." ORDER BY rowid ASC";

$resql = $db->query($sql);
if (!$resql) {
	print json_encode(array('error' => 'DB error'));
	exit;
}

$counts = array();
while ($obj = $db->fetch_object($resql)) {
	$client    = new IMAPClient();
	$connected = $client->connect(
		$obj->imap_server,
		$obj->imap_port,
		$obj->imap_security,
		$obj->imap_login,
		$obj->imap_password,
		'INBOX',
		$obj->auth_type,
		$obj->oauth_service
	);
	if ($connected) {
		$counts[(int) $obj->rowid] = $client->getUnseenCount('INBOX');
		$client->close();
	}
}

print json_encode(array('success' => true, 'data' => $counts));
