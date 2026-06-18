<?php
/**
 *	\file       ajax/add_comment.php
 *	\ingroup    inbox
 *	\brief      Add an internal comment on an email
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

$uid        = (int) GETPOST('uid', 'int');
$folder     = GETPOST('folder', 'restricthtml') ?: 'INBOX';
$message_id = GETPOST('message_id', 'restricthtml');
$comment    = trim(GETPOST('comment', 'restricthtml'));

if (!$uid)      { print json_encode(array('error' => 'Missing uid'));     exit; }
if (!$comment)  { print json_encode(array('error' => 'Empty comment'));   exit; }

// Resolve account
$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
	$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 ORDER BY rowid ASC LIMIT 1";
	$resql = $db->query($sql);
}
if (!$resql || $db->num_rows($resql) == 0) { print json_encode(array('error' => 'No active mailbox')); exit; }
$fk_account = (int) $db->fetch_object($resql)->rowid;

$c = new InboxComment($db);
$c->entity      = $conf->entity;
$c->fk_account  = $fk_account;
$c->folder      = $folder;
$c->message_uid = $uid;
$c->message_id  = $message_id ?: null;
$c->comment     = $comment;

$rowid = $c->create($user);
if ($rowid < 0) {
	print json_encode(array('error' => $c->error));
	exit;
}

// Return the new comment formatted for display
$name = trim($user->firstname.' '.$user->lastname) ?: $user->login;
$initials = '';
if ($user->firstname) $initials .= strtoupper(substr($user->firstname, 0, 1));
if ($user->lastname)  $initials .= strtoupper(substr($user->lastname,  0, 1));
if (!$initials)       $initials  = strtoupper(substr($user->login ?: 'U', 0, 2));

print json_encode(array(
	'success' => true,
	'comment' => array(
		'rowid'        => $rowid,
		'comment'      => $comment,
		'date'         => dol_print_date(dol_now(), 'dayhour'),
		'author'       => $name,
		'initials'     => $initials,
		'fk_user_creat'=> (int) $user->id,
		'is_mine'      => 1,
	),
));
