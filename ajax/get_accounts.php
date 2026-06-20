<?php
/**
 *	\file       ajax/get_accounts.php
 *	\ingroup    inbox
 *	\brief      Ajax endpoint to list active mailbox accounts accessible to the current user
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

global $db, $user;

if (empty($user->rights->inbox->read)) {
	print json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$sql = "SELECT rowid, label, email FROM ".MAIN_DB_PREFIX."inbox_account"
	." WHERE status = 1 AND (fk_user = ".((int) $user->id)." OR shared = 1)"
	." ORDER BY label ASC";

$resql = $db->query($sql);
if (!$resql) {
	print json_encode(array('error' => 'DB error: '.$db->lasterror()));
	exit;
}

$accounts = array();
while ($obj = $db->fetch_object($resql)) {
	$accounts[] = array(
		'rowid' => (int) $obj->rowid,
		'label' => $obj->label,
		'email' => $obj->email,
	);
}

print json_encode(array('success' => true, 'data' => $accounts));
