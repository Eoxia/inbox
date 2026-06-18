<?php
/**
 *	\file       ajax/get_tags.php
 *	\ingroup    inbox
 *	\brief      Return all active tags for the current entity
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxtag.class.php';

global $db, $user, $conf;

if (empty($user->rights->inbox->read)) { print json_encode(array('error' => 'Access denied')); exit; }

header('Content-Type: application/json');

$obj  = new InboxTag($db);
$list = $obj->fetchAll($conf->entity);

$data = array();
foreach ($list as $t) {
	$data[] = array(
		'rowid'        => $t->rowid,
		'label'        => $t->label,
		'color'        => $t->color,
		'imap_keyword' => $t->imap_keyword,
	);
}

print json_encode(array('success' => true, 'data' => $data));
