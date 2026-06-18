<?php
/**
 *	\file       ajax/get_attachment.php
 *	\ingroup    inbox
 *	\brief      Serve an email attachment for download
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
	http_response_code(403);
	exit('Access denied');
}

$uid      = (int) GETPOST('uid', 'int');
$partno   = GETPOST('partno', 'alphanohtml');
$filename = GETPOST('filename', 'alphanohtml');
$folder   = GETPOST('folder', 'restricthtml');
$encoding = (int) GETPOST('encoding', 'int');

if (!preg_match('/^\d+(\.\d+)*$/', $partno)) {
	http_response_code(400);
	exit('Invalid part number');
}
if (!$uid) {
	http_response_code(400);
	exit('Missing uid');
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
		http_response_code(500);
		exit('No active mailbox configured');
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
	http_response_code(500);
	exit('IMAP connection failed: '.$client->error);
}

$data = $client->getAttachmentData($uid, $partno, $encoding);
$client->close();

if ($data === '' || $data === false) {
	http_response_code(404);
	exit('Attachment not found');
}

// Sanitize filename for Content-Disposition
$safeFilename = preg_replace('/[^\w.\-() ]/', '_', $filename ?: 'attachment');

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.addslashes($safeFilename).'"');
header('Content-Length: '.strlen($data));
header('Cache-Control: private, no-cache');

echo $data;
