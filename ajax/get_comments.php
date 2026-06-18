<?php
/**
 *	\file       ajax/get_comments.php
 *	\ingroup    inbox
 *	\brief      Return internal comments for an email
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxcomment.class.php';

global $db, $user, $conf;

if (empty($user->rights->inbox->read)) { print json_encode(array('error' => 'Access denied')); exit; }

header('Content-Type: application/json');

$uid    = (int) GETPOST('uid', 'int');
$folder = GETPOST('folder', 'restricthtml') ?: 'INBOX';

if (!$uid) { print json_encode(array('error' => 'Missing uid')); exit; }

// Resolve account
$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
	$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 ORDER BY rowid ASC LIMIT 1";
	$resql = $db->query($sql);
}
if (!$resql || $db->num_rows($resql) == 0) { print json_encode(array('error' => 'No active mailbox')); exit; }
$fk_account = (int) $db->fetch_object($resql)->rowid;

$obj    = new InboxComment($db);
$list   = $obj->fetchByMessage($fk_account, $folder, $uid, $conf->entity);

$data = array();
foreach ($list as $c) {
	$name = trim($c->user_firstname.' '.$c->user_lastname) ?: $c->user_login;
	$initials = '';
	if ($c->user_firstname) $initials .= strtoupper(substr($c->user_firstname, 0, 1));
	if ($c->user_lastname)  $initials .= strtoupper(substr($c->user_lastname, 0, 1));
	if (!$initials)         $initials  = strtoupper(substr($c->user_login ?: 'U', 0, 2));

	$data[] = array(
		'rowid'        => (int) $c->rowid,
		'comment'      => $c->comment,
		'date'         => $c->date_creation,
		'author'       => $name,
		'initials'     => $initials,
		'fk_user_creat'=> (int) $c->fk_user_creat,
		'is_mine'      => ($c->fk_user_creat == $user->id || $user->admin) ? 1 : 0,
	);
}

print json_encode(array('success' => true, 'data' => $data));
