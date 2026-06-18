<?php
/**
 *	\file       ajax/delete_comment.php
 *	\ingroup    inbox
 *	\brief      Soft-delete an internal comment (own comment or admin)
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxcomment.class.php';

global $db, $user;

if (empty($user->rights->inbox->read)) { print json_encode(array('error' => 'Access denied')); exit; }

header('Content-Type: application/json');

$rowid = (int) GETPOST('rowid', 'int');
if (!$rowid) { print json_encode(array('error' => 'Missing rowid')); exit; }

$c  = new InboxComment($db);
$ok = $c->deleteComment($user, $rowid);

if ($ok < 0) {
	$msg = $ok == -2 ? 'Comment not found or not allowed' : $c->error;
	print json_encode(array('error' => $msg));
	exit;
}

print json_encode(array('success' => true));
