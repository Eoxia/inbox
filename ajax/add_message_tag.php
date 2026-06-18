<?php
/**
 *	\file       ajax/add_message_tag.php
 *	\ingroup    inbox
 *	\brief      Add a tag to an email (+ optional IMAP keyword sync)
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/imapclient.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxtag.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxmessagetag.class.php';

global $db, $user, $conf;

if (empty($user->rights->inbox->read)) { print json_encode(array('error' => 'Access denied')); exit; }

header('Content-Type: application/json');

$fk_tag     = (int) GETPOST('fk_tag', 'int');
$message_id = GETPOST('message_id', 'alphawithlgt');
$message_uid = (int) GETPOST('uid', 'int');
$folder     = GETPOST('folder', 'restricthtml') ?: 'INBOX';

if (!$fk_tag)     { print json_encode(array('error' => 'Missing fk_tag'));     exit; }
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

// Load tag to get label/color/imap_keyword
$tag = new InboxTag($db);
if ($tag->fetch($fk_tag) <= 0) { print json_encode(array('error' => 'Tag not found')); exit; }

// Persist in DB
$mt = new InboxMessageTag($db);
$mt->addTag($fk_tag, $fk_account, $message_id, $message_uid ?: null, $folder, $conf->entity, $user);

// Sync to IMAP if a keyword is defined
if (!empty($tag->imap_keyword) && $message_uid) {
	$account = new InboxAccount($db);
	$account->fetch($fk_account);

	$client = new IMAPClient();
	if ($client->connect($account->imap_server, $account->imap_port, $account->imap_security,
		$account->imap_login, $account->imap_password, $folder)) {
		$client->setKeyword($message_uid, $tag->imap_keyword);
		$client->close();
	}
}

print json_encode(array(
	'success' => true,
	'tag' => array(
		'rowid' => $tag->rowid,
		'label' => $tag->label,
		'color' => $tag->color,
	),
));
