<?php
/**
 *	\file       ajax/get_message_tags.php
 *	\ingroup    inbox
 *	\brief      Return tags applied to a specific email
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxmessagetag.class.php';

global $db, $user, $conf;

if (empty($user->rights->inbox->read)) { print json_encode(array('error' => 'Access denied')); exit; }

header('Content-Type: application/json');

$message_id = GETPOST('message_id', 'restricthtml');
if (!$message_id) { print json_encode(array('error' => 'Missing message_id')); exit; }

// Resolve account
$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 AND (fk_user = ".((int)$user->id)." OR shared = 1) ORDER BY rowid ASC LIMIT 1";
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
	$sql   = "SELECT rowid FROM ".MAIN_DB_PREFIX."inbox_account WHERE status = 1 ORDER BY rowid ASC LIMIT 1";
	$resql = $db->query($sql);
}
if (!$resql || $db->num_rows($resql) == 0) { print json_encode(array('error' => 'No active mailbox')); exit; }
$fk_account = (int) $db->fetch_object($resql)->rowid;

$obj  = new InboxMessageTag($db);
$list = $obj->fetchByMessage($fk_account, $message_id, $conf->entity);

$data = array();
foreach ($list as $mt) {
	$data[] = array(
		'rowid'        => $mt->fk_tag,
		'label'        => $mt->tag_label,
		'color'        => $mt->tag_color,
		'imap_keyword' => $mt->tag_imap_keyword,
	);
}

print json_encode(array('success' => true, 'data' => $data));
