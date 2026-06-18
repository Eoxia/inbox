<?php
/**
 *	\file       admin/setup.php
 *	\ingroup    inbox
 *	\brief      Page to setup the module (manage accounts)
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/inbox/class/inboxaccount.class.php');
dol_include_once('/inbox/lib/inbox.lib.php');

global $langs, $user, $conf, $db;

$langs->loadLangs(array("admin", "inbox@inbox"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$error = 0;

$account = new InboxAccount($db);

if ($action == 'add') {
	$account->label = GETPOST('label', 'alpha');
	$account->email = GETPOST('email', 'alpha');
	$account->imap_server = GETPOST('imap_server', 'alpha');
	$account->imap_port = GETPOST('imap_port', 'int');
	$account->imap_security = GETPOST('imap_security', 'alpha');
	$account->imap_login = GETPOST('imap_login', 'alpha');
	$account->imap_password = GETPOST('imap_password', 'none'); // should be encrypted later
	
	$account->smtp_server = GETPOST('smtp_server', 'alpha');
	$account->smtp_port = GETPOST('smtp_port', 'int');
	$account->smtp_security = GETPOST('smtp_security', 'alpha');
	$account->smtp_login = GETPOST('smtp_login', 'alpha');
	$account->smtp_password = GETPOST('smtp_password', 'none'); // should be encrypted later
	
	$account->sync_limit_nb = GETPOST('sync_limit_nb', 'int');
	$account->sync_limit_days = GETPOST('sync_limit_days', 'int');
	if (empty($account->sync_limit_nb)) $account->sync_limit_nb = 500;
	if (empty($account->sync_limit_days)) $account->sync_limit_days = 180;
	
	$account->shared = GETPOST('shared', 'int') ? 1 : 0;
	$account->status = 1;

	if (empty($account->label) || empty($account->email) || empty($account->imap_server)) {
		setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
		$error++;
	} else {
		$res = $account->create($user);
		if ($res > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			$action = '';
		} else {
			setEventMessages($account->error, $account->errors, 'errors');
			$error++;
		}
	}
} elseif ($action == 'test' && GETPOST('id', 'int')) {
	$id = GETPOST('id', 'int');
	$account->fetch($id);
	
	// IMAP Test
	$imap_ok = false;
	$imap_msg = '';
	if (function_exists('imap_open')) {
		$mailbox = '{'.$account->imap_server.':'.$account->imap_port.'/imap';
		if ($account->imap_security == 'ssl') $mailbox .= '/ssl';
		elseif ($account->imap_security == 'starttls') $mailbox .= '/tls';
		if ($account->allow_self_signed) $mailbox .= '/novalidate-cert';
		$mailbox .= '}INBOX';
		
		$imap = @imap_open($mailbox, $account->imap_login, $account->imap_password, 0, 1);
		if ($imap) {
			$imap_ok = true;
			$imap_msg = $langs->trans("InboxImapConnectOk");
			imap_close($imap);
		} else {
			$imap_msg = $langs->trans("InboxImapError").' '.imap_last_error();
		}
	} else {
		$imap_msg = $langs->trans("InboxImapError").' '.$langs->trans("InboxImapExtensionMissing");
	}

	// SMTP Test
	$smtp_ok = false;
	$smtp_msg = '';
	
	require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	
	// Backup and override Dolibarr global SMTP conf for this test
	$conf_backup = array();
	$override_keys = array('MAIN_MAIL_SENDMODE', 'MAIN_MAIL_SMTP_SERVER', 'MAIN_MAIL_SMTP_PORT', 'MAIN_MAIL_SMTPS_ID', 'MAIN_MAIL_SMTPS_PW', 'MAIN_MAIL_EMAIL_TLS', 'MAIN_MAIL_EMAIL_STARTTLS');
	foreach ($override_keys as $k) {
		$conf_backup[$k] = isset($conf->global->$k) ? $conf->global->$k : '';
	}

	$conf->global->MAIN_MAIL_SENDMODE = 'smtps';
	$conf->global->MAIN_MAIL_SMTP_SERVER = $account->smtp_server;
	$conf->global->MAIN_MAIL_SMTP_PORT = $account->smtp_port;
	$conf->global->MAIN_MAIL_SMTPS_ID = $account->smtp_login;
	$conf->global->MAIN_MAIL_SMTPS_PW = $account->smtp_password;
	$conf->global->MAIN_MAIL_EMAIL_TLS = ($account->smtp_security == 'ssl' ? 1 : 0);
	$conf->global->MAIN_MAIL_EMAIL_STARTTLS = ($account->smtp_security == 'starttls' ? 1 : 0);

	$mailfile = new CMailFile('Test Inbox Dolibarr', $account->email, $account->email, 'Ceci est un email de test généré depuis la configuration du module Inbox Dolibarr pour valider les identifiants SMTP.', array(), array(), array(), '', '', 0, 0);
	$res_send = $mailfile->sendfile();
	
	if ($res_send) {
		$smtp_ok = true;
		$smtp_msg = $langs->trans("InboxSmtpConnectOk");
	} else {
		$smtp_msg = $langs->trans("InboxSmtpError").' '.$mailfile->error;
	}

	// Restore
	foreach ($override_keys as $k) {
		$conf->global->$k = $conf_backup[$k];
	}

	if ($imap_ok && $smtp_ok) {
		setEventMessages($imap_msg . "<br>" . $smtp_msg, null, 'mesgs');
	} else {
		setEventMessages($imap_msg . "<br>" . $smtp_msg, null, 'errors');
	}
	$action = '';
} elseif ($action == 'edit' && GETPOST('id', 'int')) {
	$id = GETPOST('id', 'int');
	$account->fetch($id);
} elseif ($action == 'update' && GETPOST('id', 'int')) {
	$id = GETPOST('id', 'int');
	$account->fetch($id);
	
	$account->label = GETPOST('label', 'alpha');
	$account->email = GETPOST('email', 'alpha');
	$account->imap_server = GETPOST('imap_server', 'alpha');
	$account->imap_port = GETPOST('imap_port', 'int');
	$account->imap_security = GETPOST('imap_security', 'alpha');
	$account->imap_login = GETPOST('imap_login', 'alpha');
	if (GETPOST('imap_password', 'none') != '') $account->imap_password = GETPOST('imap_password', 'none');
	
	$account->smtp_server = GETPOST('smtp_server', 'alpha');
	$account->smtp_port = GETPOST('smtp_port', 'int');
	$account->smtp_security = GETPOST('smtp_security', 'alpha');
	$account->smtp_login = GETPOST('smtp_login', 'alpha');
	if (GETPOST('smtp_password', 'none') != '') $account->smtp_password = GETPOST('smtp_password', 'none');
	
	$account->sync_limit_nb = GETPOST('sync_limit_nb', 'int');
	$account->sync_limit_days = GETPOST('sync_limit_days', 'int');
	if (empty($account->sync_limit_nb)) $account->sync_limit_nb = 500;
	if (empty($account->sync_limit_days)) $account->sync_limit_days = 180;
	
	$account->shared = GETPOST('shared', 'int') ? 1 : 0;
	
	// Update directly for now (missing update method in class, so we use SQL for quick V1)
	$sql = "UPDATE ".MAIN_DB_PREFIX."inbox_account SET ";
	$sql .= "label = '".$db->escape($account->label)."', email = '".$db->escape($account->email)."', ";
	$sql .= "imap_server = '".$db->escape($account->imap_server)."', imap_port = ".(int)$account->imap_port.", ";
	$sql .= "imap_security = '".$db->escape($account->imap_security)."', imap_login = '".$db->escape($account->imap_login)."', ";
	$sql .= "imap_password = '".$db->escape($account->imap_password)."', ";
	$sql .= "smtp_server = '".$db->escape($account->smtp_server)."', smtp_port = ".(int)$account->smtp_port.", ";
	$sql .= "smtp_security = '".$db->escape($account->smtp_security)."', smtp_login = '".$db->escape($account->smtp_login)."', ";
	$sql .= "smtp_password = '".$db->escape($account->smtp_password)."', ";
	$sql .= "sync_limit_nb = ".(int)$account->sync_limit_nb.", sync_limit_days = ".(int)$account->sync_limit_days.", ";
	$sql .= "shared = ".(int)$account->shared." ";
	$sql .= "WHERE rowid = ".(int)$account->id;
	
	$resql = $db->query($sql);
	if ($resql) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		$action = '';
		$account = new InboxAccount($db); // clear for next view
	} else {
		setEventMessages($langs->trans("InboxUpdateError"), null, 'errors');
	}
}

/*
 * View
 */

$page_name = "InboxSetup";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = adminInboxPrepareHead();
print dol_get_fiche_head($head, 'accounts', '', -1, 'fa-envelope');

print '<div class="info">'.$langs->trans("InboxSetupPageDesc").'</div>';

// List of accounts
$newbtn = '<a href="'.$_SERVER["PHP_SELF"].'?action=create" class="butAction">'.$langs->trans("AddNewAccount").'</a>';
print load_fiche_titre($langs->trans("InboxAccountsList"), $newbtn, '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Label").'</td>';
print '<td>'.$langs->trans("Email").'</td>';
print '<td>'.$langs->trans("IMAPServer").'</td>';
print '<td>'.$langs->trans("SMTPServer").'</td>';
print '<td class="center">'.$langs->trans("Shared").'</td>';
print '<td class="center">'.$langs->trans("Status").'</td>';
print '<td class="center"></td>'; // Action column
print '</tr>';

$sql = "SELECT rowid, label, email, imap_server, smtp_server, shared, status FROM ".MAIN_DB_PREFIX."inbox_account ORDER BY label ASC";
$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;
	if ($num > 0) {
		while ($i < $num) {
			$obj = $db->fetch_object($resql);
			print '<tr class="oddeven">';
			print '<td>'.$obj->label.'</td>';
			print '<td>'.$obj->email.'</td>';
			print '<td>'.$obj->imap_server.'</td>';
			print '<td>'.$obj->smtp_server.'</td>';
			print '<td class="center">'.yn($obj->shared).'</td>';
			print '<td class="center">';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=test&id='.$obj->rowid.'" title="'.$langs->trans("TestConnection").'">'.img_picto($langs->trans("TestConnection"), 'email').'</a> &nbsp; ';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$obj->rowid.'" title="'.$langs->trans("Edit").'">'.img_edit().'</a> &nbsp; ';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$obj->rowid.'" title="'.$langs->trans("Delete").'">'.img_delete().'</a>';
			print '</td>';
			print '</tr>';
			$i++;
		}
	} else {
		print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans("None").'</td></tr>';
	}
} else {
	if ($db->lasterrno == 'DB_ERROR_NOSUCHTABLE') {
		print '<tr class="oddeven"><td colspan="7"><div class="warning">'.$langs->trans("WarningTableNotExists").'</div></td></tr>';
	} else {
		dol_print_error($db);
	}
}
print '</table>';

if (in_array($action, array('create', 'edit', 'add', 'update')) || $error) {
	// Form to add or edit an account
	print '<br>';
	print load_fiche_titre($action == 'edit' ? $langs->trans("EditAccount") : $langs->trans("AddNewAccount"), '', '');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($action == 'edit' ? 'update' : 'add').'">';
	if ($action == 'edit') {
		print '<input type="hidden" name="id" value="'.$account->id.'">';
	}
	
	print '<table class="border centpercent">';
	// General
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("General").'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Label").'</td><td><input type="text" name="label" value="'.dol_escape_htmltag($account->label).'" size="40"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Email").'</td><td><input type="text" name="email" value="'.dol_escape_htmltag($account->email).'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Shared").'</td><td><select name="shared"><option value="0"'.($account->shared == 0 ? ' selected' : '').'>'.$langs->trans("No").'</option><option value="1"'.($account->shared == 1 ? ' selected' : '').'>'.$langs->trans("Yes").'</option></select></td></tr>';
	print '<tr><td>'.$langs->trans("Status").'</td><td><select name="status"><option value="1"'.($account->status == 1 ? ' selected' : '').'>'.$langs->trans("Active").'</option><option value="0"'.($account->status == 0 ? ' selected' : '').'>'.$langs->trans("Inactive").'</option></select></td></tr>';
	
	// IMAP
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("IMAPConfig").'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Server").'</td><td><input type="text" name="imap_server" value="'.($account->imap_server ? dol_escape_htmltag($account->imap_server) : 'imap.').'" size="40"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Port").'</td><td><input type="text" name="imap_port" value="'.($account->imap_port ? $account->imap_port : '993').'" size="6"></td></tr>';
	print '<tr><td>'.$langs->trans("Security").'</td><td><select name="imap_security">';
	print '<option value="none"'.($account->imap_security == 'none' ? ' selected' : '').'>None</option>';
	print '<option value="ssl"'.($account->imap_security == 'ssl' ? ' selected' : '').'>SSL/TLS</option>';
	print '<option value="starttls"'.($account->imap_security == 'starttls' ? ' selected' : '').'>STARTTLS</option>';
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Login").'</td><td><input type="text" name="imap_login" value="'.dol_escape_htmltag($account->imap_login).'" size="40"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Password").'</td><td><input type="password" name="imap_password" size="40">'.($action == 'edit'?' <span class="opacitymedium">Laissez vide pour conserver</span>':'').'</td></tr>';

	// SMTP
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("SMTPConfig").'</td></tr>';
	print '<tr><td>'.$langs->trans("Server").'</td><td><input type="text" name="smtp_server" value="'.($account->smtp_server ? dol_escape_htmltag($account->smtp_server) : 'smtp.').'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Port").'</td><td><input type="text" name="smtp_port" value="'.($account->smtp_port ? $account->smtp_port : '465').'" size="6"></td></tr>';
	print '<tr><td>'.$langs->trans("Security").'</td><td><select name="smtp_security">';
	print '<option value="none"'.($account->smtp_security == 'none' ? ' selected' : '').'>None</option>';
	print '<option value="ssl"'.($account->smtp_security == 'ssl' ? ' selected' : '').'>SSL/TLS</option>';
	print '<option value="starttls"'.($account->smtp_security == 'starttls' ? ' selected' : '').'>STARTTLS</option>';
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans("Login").'</td><td><input type="text" name="smtp_login" value="'.dol_escape_htmltag($account->smtp_login).'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Password").'</td><td><input type="password" name="smtp_password" size="40">'.($action == 'edit'?' <span class="opacitymedium">Laissez vide pour conserver</span>':'').'</td></tr>';

	// Sync Limits
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("InboxSyncLimits").'</td></tr>';
	print '<tr><td>'.$langs->trans("InboxSyncLimitNb").'</td><td><input type="number" name="sync_limit_nb" value="'.($account->sync_limit_nb ? $account->sync_limit_nb : '500').'" size="6"> <span class="opacitymedium">'.$langs->trans("InboxSyncLimitNbDefault").'</span></td></tr>';
	print '<tr><td>'.$langs->trans("InboxSyncLimitDays").'</td><td><input type="number" name="sync_limit_days" value="'.($account->sync_limit_days ? $account->sync_limit_days : '180').'" size="6"> <span class="opacitymedium">'.$langs->trans("InboxSyncLimitDaysDefault").'</span></td></tr>';

	print '</table>';

	print '<div class="center"><br>';
	print '<input type="submit" class="button button-save" value="'.($action == 'edit' ? $langs->trans("Save") : $langs->trans("Add")).'">';
	if ($action == 'edit') {
		print ' &nbsp; <a href="'.$_SERVER["PHP_SELF"].'" class="button button-cancel">'.$langs->trans("Cancel").'</a>';
	}
	print '</div>';

	print '</form>';
} else {
	// Only show global params if not editing an account
	print '<br>';
	print load_fiche_titre($langs->trans("InboxGlobalSettings"), '', '');

	if ($action == 'set_global') {
		$delay = GETPOST('inbox_send_delay', 'int');
		dolibarr_set_const($db, 'INBOX_SEND_DELAY', $delay, 'chaine', 0, '', $conf->entity);
		$refresh = GETPOST('inbox_refresh_interval', 'int');
		dolibarr_set_const($db, 'INBOX_REFRESH_INTERVAL', max(0, $refresh), 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
	}

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="set_global">';

	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans("InboxSendDelayLabel").'</td><td>';
	print '<input type="number" name="inbox_send_delay" value="'.getDolGlobalInt('INBOX_SEND_DELAY', 10).'" min="0" size="6">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxSendDelayHelp").'</span>';
	print '</td></tr>';
	print '<tr><td class="titlefield">'.$langs->trans("InboxRefreshIntervalLabel").'</td><td>';
	print '<input type="number" name="inbox_refresh_interval" value="'.getDolGlobalInt('INBOX_REFRESH_INTERVAL', 0).'" min="0" size="6">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxRefreshIntervalHelp").'</span>';
	print '</td></tr>';
	print '</table>';
	print '<div class="center"><br><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
	print '</form>';
}

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();
