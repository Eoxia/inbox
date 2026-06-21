<?php
/**
 *	\file       admin/setup.php
 *	\ingroup    inbox
 *	\brief      Module setup page — manage accounts (IMAP and WhatsApp)
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
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
if (!$res && file_exists("../../main.inc.php"))    $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/oauth.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
dol_include_once('/inbox/class/inboxaccount.class.php');
dol_include_once('/inbox/lib/inbox.lib.php');

global $langs, $user, $conf, $db;

$langs->loadLangs(array("admin", "inbox@inbox"));

if (!$user->admin) accessforbidden();

$action  = GETPOST('action', 'aZ09');
$error   = 0;
$account = new InboxAccount($db);

// ── Helper: build config JSON from posted WhatsApp fields ─────────────────────
function buildWhatsAppConfig()
{
	$cfg = [];
	$pid = GETPOST('wa_phone_number_id', 'alpha');
	$tok = GETPOST('wa_access_token', 'none');
	$vtk = GETPOST('wa_verify_token', 'alpha');
	$ver = GETPOST('wa_api_version', 'alpha');
	if ($pid) $cfg['phone_number_id'] = $pid;
	if ($tok) $cfg['access_token']    = $tok;
	if ($vtk) $cfg['verify_token']    = $vtk;
	if ($ver) $cfg['api_version']     = $ver;
	return json_encode($cfg);
}

// ── Actions ───────────────────────────────────────────────────────────────────

if ($action == 'add') {

	$account->label        = GETPOST('label', 'alpha');
	$account->email        = GETPOST('email', 'alpha');
	$account->shared       = GETPOST('shared', 'int') ? 1 : 0;
	$account->status       = GETPOST('status', 'int') ? 1 : 0;
	$account->provider_type = in_array(GETPOST('provider_type', 'alpha'), ['imap', 'whatsapp'])
		? GETPOST('provider_type', 'alpha') : 'imap';

	if ($account->provider_type === 'whatsapp') {
		$account->config = buildWhatsAppConfig();
		$cfg = $account->getConfig();
		if (empty($account->label) || empty($cfg['phone_number_id']) || empty($cfg['access_token'])) {
			setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
			$error++;
		}
	} else {
		$account->imap_server   = GETPOST('imap_server', 'alpha');
		$account->imap_port     = GETPOST('imap_port', 'int');
		$account->imap_security = GETPOST('imap_security', 'alpha');
		$account->imap_login    = GETPOST('imap_login', 'alpha');
		$account->imap_password = GETPOST('imap_password', 'none');
		$account->smtp_server   = GETPOST('smtp_server', 'alpha');
		$account->smtp_port     = GETPOST('smtp_port', 'int');
		$account->smtp_security = GETPOST('smtp_security', 'alpha');
		$account->smtp_login    = GETPOST('smtp_login', 'alpha');
		$account->smtp_password = GETPOST('smtp_password', 'none');
		$account->auth_type     = in_array(GETPOST('auth_type', 'alpha'), ['password', 'oauth2'])
			? GETPOST('auth_type', 'alpha') : 'password';
		$account->oauth_service = GETPOST('oauth_service', 'alphanohtml');
		$account->sync_limit_nb   = GETPOST('sync_limit_nb', 'int') ?: 500;
		$account->sync_limit_days = GETPOST('sync_limit_days', 'int') ?: 180;

		if (empty($account->label) || empty($account->email) || empty($account->imap_server)) {
			setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
			$error++;
		}
	}

	if (!$error) {
		$res = $account->create($user);
		if ($res > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			$action = '';
		} else {
			setEventMessages($account->error, $account->errors, 'errors');
			$error++;
		}
	}

} elseif ($action == 'edit' && GETPOST('id', 'int')) {

	$account->fetch((int) GETPOST('id', 'int'));

} elseif ($action == 'update' && GETPOST('id', 'int')) {

	$id = (int) GETPOST('id', 'int');
	$account->fetch($id);

	$account->label         = GETPOST('label', 'alpha');
	$account->email         = GETPOST('email', 'alpha');
	$account->shared        = GETPOST('shared', 'int') ? 1 : 0;
	$account->status        = GETPOST('status', 'int') ? 1 : 0;
	$account->provider_type = in_array(GETPOST('provider_type', 'alpha'), ['imap', 'whatsapp'])
		? GETPOST('provider_type', 'alpha') : 'imap';

	if ($account->provider_type === 'whatsapp') {
		// Merge new config with existing (preserve access_token if not changed)
		$existing = $account->getConfig();
		$newPid = GETPOST('wa_phone_number_id', 'alpha');
		$newTok = GETPOST('wa_access_token', 'none');
		$newVtk = GETPOST('wa_verify_token', 'alpha');
		$newVer = GETPOST('wa_api_version', 'alpha');
		if ($newPid) $existing['phone_number_id'] = $newPid;
		if ($newTok) $existing['access_token']    = $newTok;
		if ($newVtk) $existing['verify_token']    = $newVtk;
		if ($newVer) $existing['api_version']     = $newVer;
		$account->config = json_encode($existing);

		$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_account SET";
		$sql .= " label='".$db->escape($account->label)."',";
		$sql .= " email='".$db->escape($account->email)."',";
		$sql .= " shared=".(int) $account->shared.",";
		$sql .= " status=".(int) $account->status.",";
		$sql .= " provider_type='whatsapp',";
		$sql .= " config='".$db->escape($account->config)."'";
		$sql .= " WHERE rowid=".$id;
	} else {
		$account->imap_server   = GETPOST('imap_server', 'alpha');
		$account->imap_port     = GETPOST('imap_port', 'int');
		$account->imap_security = GETPOST('imap_security', 'alpha');
		$account->imap_login    = GETPOST('imap_login', 'alpha');
		if (GETPOST('imap_password', 'none') != '') {
			$account->imap_password = GETPOST('imap_password', 'none');
		}
		$account->smtp_server   = GETPOST('smtp_server', 'alpha');
		$account->smtp_port     = GETPOST('smtp_port', 'int');
		$account->smtp_security = GETPOST('smtp_security', 'alpha');
		$account->smtp_login    = GETPOST('smtp_login', 'alpha');
		if (GETPOST('smtp_password', 'none') != '') {
			$account->smtp_password = GETPOST('smtp_password', 'none');
		}
		$account->auth_type     = in_array(GETPOST('auth_type', 'alpha'), ['password', 'oauth2'])
			? GETPOST('auth_type', 'alpha') : 'password';
		$account->oauth_service = GETPOST('oauth_service', 'alphanohtml');
		$account->sync_limit_nb   = GETPOST('sync_limit_nb', 'int') ?: 500;
		$account->sync_limit_days = GETPOST('sync_limit_days', 'int') ?: 180;

		$sql  = "UPDATE ".MAIN_DB_PREFIX."inbox_account SET";
		$sql .= " label='".$db->escape($account->label)."',";
		$sql .= " email='".$db->escape($account->email)."',";
		$sql .= " imap_server='".$db->escape($account->imap_server)."',";
		$sql .= " imap_port=".(int) $account->imap_port.",";
		$sql .= " imap_security='".$db->escape($account->imap_security)."',";
		$sql .= " imap_login='".$db->escape($account->imap_login)."',";
		$sql .= " imap_password='".$db->escape($account->imap_password)."',";
		$sql .= " smtp_server='".$db->escape($account->smtp_server)."',";
		$sql .= " smtp_port=".(int) $account->smtp_port.",";
		$sql .= " smtp_security='".$db->escape($account->smtp_security)."',";
		$sql .= " smtp_login='".$db->escape($account->smtp_login)."',";
		$sql .= " smtp_password='".$db->escape($account->smtp_password)."',";
		$sql .= " sync_limit_nb=".(int) $account->sync_limit_nb.",";
		$sql .= " sync_limit_days=".(int) $account->sync_limit_days.",";
		$sql .= " auth_type='".$db->escape($account->auth_type)."',";
		$sql .= " oauth_service='".$db->escape($account->oauth_service)."',";
		$sql .= " shared=".(int) $account->shared.",";
		$sql .= " status=".(int) $account->status.",";
		$sql .= " provider_type='imap'";
		$sql .= " WHERE rowid=".$id;
	}

	if ($db->query($sql)) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		$action  = '';
		$account = new InboxAccount($db);
	} else {
		setEventMessages($langs->trans("InboxUpdateError"), null, 'errors');
	}

} elseif ($action == 'test' && GETPOST('id', 'int')) {

	$account->fetch((int) GETPOST('id', 'int'));

	if ($account->provider_type === 'whatsapp') {
		$cfg  = $account->getConfig();
		$pid  = $cfg['phone_number_id'] ?? '';
		$tok  = $cfg['access_token']    ?? '';
		$ver  = $cfg['api_version']     ?? 'v20.0';
		if (empty($pid) || empty($tok)) {
			setEventMessages('WhatsApp config incomplete (phone_number_id / access_token)', null, 'errors');
		} else {
			$result = getURLContent(
				'https://graph.facebook.com/'.$ver.'/'.$pid,
				'GET', '', 1,
				['Authorization: Bearer '.$tok]
			);
			$data = !empty($result['content']) ? json_decode($result['content'], true) : [];
			if (!empty($data['display_phone_number'])) {
				setEventMessages('WhatsApp OK — numéro : '.$data['display_phone_number'].' ('.$data['verified_name'].')', null, 'mesgs');
			} elseif (!empty($data['error'])) {
				setEventMessages('WhatsApp error: '.$data['error']['message'], null, 'errors');
			} else {
				setEventMessages('Réponse inattendue de l\'API Meta', null, 'errors');
			}
		}
	} else {
		// IMAP Test (using Horde via IMAPClient)
		dol_include_once('/inbox/class/imapclient.class.php');
		$client   = new IMAPClient();
		$imap_ok  = $client->connect(
			$account->imap_server, $account->imap_port, $account->imap_security,
			$account->imap_login, $account->imap_password, 'INBOX',
			$account->auth_type, $account->oauth_service
		);
		$imap_msg = $imap_ok ? $langs->trans("InboxImapConnectOk") : $langs->trans("InboxImapError").' '.$client->error;
		if ($imap_ok) $client->close();

		// SMTP Test
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$bak = [];
		$keys = ['MAIN_MAIL_SENDMODE', 'MAIN_MAIL_SMTP_SERVER', 'MAIN_MAIL_SMTP_PORT',
			'MAIN_MAIL_SMTPS_ID', 'MAIN_MAIL_SMTPS_PW', 'MAIN_MAIL_EMAIL_TLS', 'MAIN_MAIL_EMAIL_STARTTLS'];
		foreach ($keys as $k) $bak[$k] = $conf->global->$k ?? '';
		$conf->global->MAIN_MAIL_SENDMODE        = 'smtps';
		$conf->global->MAIN_MAIL_SMTP_SERVER     = $account->smtp_server;
		$conf->global->MAIN_MAIL_SMTP_PORT       = $account->smtp_port;
		$conf->global->MAIN_MAIL_SMTPS_ID        = $account->smtp_login;
		$conf->global->MAIN_MAIL_SMTPS_PW        = $account->smtp_password;
		$conf->global->MAIN_MAIL_EMAIL_TLS       = ($account->smtp_security == 'ssl'      ? 1 : 0);
		$conf->global->MAIN_MAIL_EMAIL_STARTTLS  = ($account->smtp_security == 'starttls' ? 1 : 0);
		$mailfile  = new CMailFile('Test Inbox', $account->email, $account->email, 'Test SMTP depuis Inbox.', [], [], [], '', '', 0, 0);
		$smtp_ok   = $mailfile->sendfile();
		$smtp_msg  = $smtp_ok ? $langs->trans("InboxSmtpConnectOk") : $langs->trans("InboxSmtpError").' '.$mailfile->error;
		foreach ($keys as $k) $conf->global->$k = $bak[$k];

		if ($imap_ok && $smtp_ok) setEventMessages($imap_msg.'<br>'.$smtp_msg, null, 'mesgs');
		else                       setEventMessages($imap_msg.'<br>'.$smtp_msg, null, 'errors');
	}
	$action = '';

} elseif ($action == 'delete' && GETPOST('id', 'int')) {

	$id  = (int) GETPOST('id', 'int');
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."inbox_account WHERE rowid = ".$id;
	if ($db->query($sql)) {
		setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	$action = '';

} elseif ($action == 'set_global') {

	dolibarr_set_const($db, 'INBOX_SEND_DELAY',         (int) GETPOST('inbox_send_delay', 'int'),     'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'INBOX_REFRESH_INTERVAL',   max(0, (int) GETPOST('inbox_refresh_interval', 'int')), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'INBOX_BLOCK_REMOTE_IMAGES', GETPOST('inbox_block_remote_images', 'int') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
	$action = '';
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

// ── Account list ──────────────────────────────────────────────────────────────

$newbtn = '<a href="'.$_SERVER["PHP_SELF"].'?action=create" class="butAction">'.$langs->trans("AddNewAccount").'</a>';
print load_fiche_titre($langs->trans("InboxAccountsList"), $newbtn, '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Label").'</td>';
print '<td>'.$langs->trans("Email").'</td>';
print '<td>'.$langs->trans("Type").'</td>';
print '<td>'.$langs->trans("IMAPServer").'</td>';
print '<td class="center">'.$langs->trans("Shared").'</td>';
print '<td class="center">'.$langs->trans("Status").'</td>';
print '<td class="center"></td>';
print '</tr>';

$sql = "SELECT rowid, label, email, imap_server, provider_type, shared, status FROM ".MAIN_DB_PREFIX."inbox_account ORDER BY label ASC";
$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	if ($num > 0) {
		while ($obj = $db->fetch_object($resql)) {
			$typeLabel = ($obj->provider_type === 'whatsapp')
				? '<span class="badge" style="background:#25D366;color:#fff">WhatsApp</span>'
				: '<span class="badge" style="background:#4a90d9;color:#fff">IMAP</span>';
			$server = ($obj->provider_type === 'whatsapp') ? '<span class="opacitymedium">Meta Cloud API</span>' : dol_escape_htmltag($obj->imap_server);
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag($obj->label).'</td>';
			print '<td>'.dol_escape_htmltag($obj->email).'</td>';
			print '<td>'.$typeLabel.'</td>';
			print '<td>'.$server.'</td>';
			print '<td class="center">'.yn($obj->shared).'</td>';
			print '<td class="center">'.($obj->status
				? '<span class="badge badge-status4">'.$langs->trans("Active").'</span>'
				: '<span class="badge badge-status5">'.$langs->trans("Inactive").'</span>').'</td>';
			print '<td class="center">';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=test&id='.$obj->rowid.'" title="'.$langs->trans("TestConnection").'">'.img_picto($langs->trans("TestConnection"), 'email').'</a> &nbsp; ';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$obj->rowid.'" title="'.$langs->trans("Edit").'">'.img_edit().'</a> &nbsp; ';
			print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$obj->rowid.'" title="'.$langs->trans("Delete").'" onclick="return confirm(\''.dol_escape_js($langs->trans("ConfirmDeleteAccount")).'\')">';
			print img_delete().'</a>';
			print '</td>';
			print '</tr>';
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
print '</table></div>';

// ── Account form ──────────────────────────────────────────────────────────────

if (in_array($action, ['create', 'edit', 'add', 'update']) || $error) {
	print '<br>';
	print load_fiche_titre($action == 'edit' ? $langs->trans("EditAccount") : $langs->trans("AddNewAccount"), '', '');

	$isEdit    = ($action == 'edit');
	$formAction = $isEdit ? 'update' : 'add';
	$cfg        = $account->getConfig(); // decoded WhatsApp config (empty array for IMAP)
	$curType    = $account->provider_type ?: 'imap';

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formAction.'">';
	if ($isEdit) print '<input type="hidden" name="id" value="'.$account->id.'">';

	print '<table class="border centpercent">';

	// ── General ───────────────────────────────────────────────────────────────
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("General").'</td></tr>';
	print '<tr><td class="fieldrequired titlefield">'.$langs->trans("Label").'</td><td><input type="text" name="label" value="'.dol_escape_htmltag($account->label).'" size="40"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Email").'</td><td><input type="text" name="email" value="'.dol_escape_htmltag($account->email).'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Shared").'</td><td>';
	print '<select name="shared"><option value="0"'.($account->shared == 0 ? ' selected' : '').'>'.$langs->trans("No").'</option><option value="1"'.($account->shared == 1 ? ' selected' : '').'>'.$langs->trans("Yes").'</option></select>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("Status").'</td><td>';
	print '<select name="status"><option value="1"'.($account->status == 1 ? ' selected' : '').'>'.$langs->trans("Active").'</option><option value="0"'.($account->status == 0 ? ' selected' : '').'>'.$langs->trans("Inactive").'</option></select>';
	print '</td></tr>';

	// ── Provider type ─────────────────────────────────────────────────────────
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("InboxProviderType").'</td></tr>';
	print '<tr><td>'.$langs->trans("InboxProviderTypeLabel").'</td><td>';
	print '<label style="margin-right:20px"><input type="radio" name="provider_type" value="imap" id="ptype_imap"'.($curType !== 'whatsapp' ? ' checked' : '').'> <strong>IMAP / SMTP</strong> &nbsp;<span class="opacitymedium">'.dol_escape_htmltag($langs->trans("InboxProviderImapDesc")).'</span></label>';
	print '<label><input type="radio" name="provider_type" value="whatsapp" id="ptype_wa"'.($curType === 'whatsapp' ? ' checked' : '').'> <strong>WhatsApp Business</strong> &nbsp;<span class="opacitymedium">'.dol_escape_htmltag($langs->trans("InboxProviderWhatsAppDesc")).'</span></label>';
	print '</td></tr>';

	// ── WhatsApp config (shown only when provider_type = whatsapp) ────────────
	$waStyle  = ($curType !== 'whatsapp') ? ' style="display:none"' : '';
	$waStyleR = ($curType !== 'whatsapp') ? ' style="display:none"' : '';

	// Compute webhook URL (only meaningful when editing, i.e. ID known)
	$webhookUrl = '';
	if ($isEdit && $account->id) {
		$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$webhookUrl = $proto.'://'.$_SERVER['HTTP_HOST'].DOL_URL_ROOT.'/custom/inbox/ajax/whatsapp_webhook.php?account_id='.$account->id;
	}

	print '<tbody id="section_whatsapp"'.$waStyle.'>';
	print '<tr><td colspan="2" class="liste_titre">WhatsApp Business Cloud API</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("InboxWAPhoneNumberId").'</td>';
	print '<td><input type="text" name="wa_phone_number_id" value="'.dol_escape_htmltag($cfg['phone_number_id'] ?? '').'" size="30" placeholder="123456789012345">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxWAPhoneNumberIdHelp").'</span></td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans("InboxWAAccessToken").'</td>';
	print '<td><input type="password" name="wa_access_token" size="60" autocomplete="new-password">';
	if ($isEdit && !empty($cfg['access_token'])) {
		print ' <span class="opacitymedium">'.$langs->trans("LeaveEmptyToKeep").'</span>';
	}
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans("InboxWAVerifyToken").'</td>';
	print '<td><input type="text" name="wa_verify_token" value="'.dol_escape_htmltag($cfg['verify_token'] ?? '').'" size="40">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxWAVerifyTokenHelp").'</span></td></tr>';

	print '<tr><td>'.$langs->trans("InboxWAApiVersion").'</td>';
	print '<td><input type="text" name="wa_api_version" value="'.dol_escape_htmltag($cfg['api_version'] ?? 'v20.0').'" size="10">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxWAApiVersionHelp").'</span></td></tr>';

	if ($webhookUrl) {
		print '<tr><td>'.$langs->trans("InboxWAWebhookUrl").'</td>';
		print '<td><code style="font-size:0.9em;background:#f5f5f5;padding:4px 8px;border-radius:3px;user-select:all">'.dol_escape_htmltag($webhookUrl).'</code>';
		print '<br><small class="opacitymedium">'.$langs->trans("InboxWAWebhookUrlHelp").'</small></td></tr>';
	} else {
		print '<tr><td>'.$langs->trans("InboxWAWebhookUrl").'</td>';
		print '<td><span class="opacitymedium">'.$langs->trans("InboxWAWebhookUrlAfterSave").'</span></td></tr>';
	}
	print '</tbody>';

	// ── IMAP config ───────────────────────────────────────────────────────────
	$imapStyle = ($curType === 'whatsapp') ? ' style="display:none"' : '';

	print '<tbody id="section_imap"'.$imapStyle.'>';
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("IMAPConfig").'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Server").'</td>';
	print '<td><input type="text" name="imap_server" value="'.dol_escape_htmltag($account->imap_server ?: 'imap.').'" size="40"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Port").'</td>';
	print '<td><input type="text" name="imap_port" value="'.($account->imap_port ?: '993').'" size="6"></td></tr>';
	print '<tr><td>'.$langs->trans("Security").'</td><td><select name="imap_security">';
	print '<option value="none"'.($account->imap_security == 'none' ? ' selected' : '').'>None</option>';
	print '<option value="ssl"'.($account->imap_security == 'ssl' || !$account->imap_security ? ' selected' : '').'>SSL/TLS</option>';
	print '<option value="starttls"'.($account->imap_security == 'starttls' ? ' selected' : '').'>STARTTLS</option>';
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("Login").'</td>';
	print '<td><input type="text" name="imap_login" value="'.dol_escape_htmltag($account->imap_login).'" size="40"></td></tr>';

	// Auth type
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("InboxAuthType").'</td></tr>';
	print '<tr><td>'.$langs->trans("InboxAuthTypeLabel").'</td><td>';
	print '<label style="margin-right:15px"><input type="radio" name="auth_type" value="password" id="auth_type_password"'.($account->auth_type != 'oauth2' ? ' checked' : '').'> '.$langs->trans("InboxAuthTypePassword").'</label>';
	print '<label><input type="radio" name="auth_type" value="oauth2" id="auth_type_oauth2"'.($account->auth_type == 'oauth2' ? ' checked' : '').'> '.$langs->trans("InboxAuthTypeOAuth2").'</label>';
	print '</td></tr>';

	print '<tr id="row_imap_password"><td>'.$langs->trans("Password").'</td>';
	print '<td><input type="password" name="imap_password" size="40">'.($isEdit ? ' <span class="opacitymedium">'.$langs->trans("LeaveEmptyToKeep").'</span>' : '').'</td></tr>';

	$supportedoauth2array = getSupportedOauth2Array();
	$imapProviders = [];
	foreach ($conf->global as $key => $val) {
		if (!empty($val) && preg_match('/^OAUTH_(.+)_ID$/', $key, $m)) {
			$servicekey  = $m[1];
			$providerbase = preg_replace('/-.*$/', '', $servicekey);
			$arraykey    = 'OAUTH_'.$providerbase.'_NAME';
			if (isset($supportedoauth2array[$arraykey])) {
				$scopes = $supportedoauth2array[$arraykey]['availablescopes'];
				if (strpos($scopes, 'gmail_full') !== false || strpos($scopes, 'IMAP.AccessAsUser.All') !== false) {
					$label = $supportedoauth2array[$arraykey]['name'].($servicekey !== $providerbase ? ' ('.$servicekey.')' : '');
					$imapProviders[$servicekey] = $label;
				}
			}
		}
	}
	$oauthStyle = ($account->auth_type == 'oauth2') ? '' : 'display:none';
	print '<tr id="row_oauth_service" style="'.$oauthStyle.'"><td>'.$langs->trans("InboxOAuthService").'</td><td>';
	if (empty($imapProviders)) {
		print '<span class="opacitymedium">'.$langs->trans("InboxOAuthNoProvider").'</span>';
		print ' <a href="'.DOL_URL_ROOT.'/admin/oauth.php">'.$langs->trans("InboxOAuthConfigureLink").'</a>';
	} else {
		print '<select name="oauth_service">';
		print '<option value=""></option>';
		foreach ($imapProviders as $skey => $slabel) {
			print '<option value="'.dol_escape_htmltag($skey).'"'.($account->oauth_service == $skey ? ' selected' : '').'>'.dol_escape_htmltag($slabel).'</option>';
		}
		print '</select>';
	}
	print '</td></tr>';
	print '<tr id="row_oauth_link" style="'.$oauthStyle.'"><td>'.$langs->trans("InboxOAuthStatus").'</td><td>';
	print '<span class="opacitymedium">'.$langs->trans("InboxOAuthStatusHelp").'</span> ';
	print '<a href="'.DOL_URL_ROOT.'/admin/oauth.php" target="_blank">'.$langs->trans("InboxOAuthManageTokens").'</a>';
	print '</td></tr>';

	// SMTP
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("SMTPConfig").'</td></tr>';
	print '<tr><td>'.$langs->trans("Server").'</td>';
	print '<td><input type="text" name="smtp_server" value="'.dol_escape_htmltag($account->smtp_server ?: 'smtp.').'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Port").'</td>';
	print '<td><input type="text" name="smtp_port" value="'.($account->smtp_port ?: '465').'" size="6"></td></tr>';
	print '<tr><td>'.$langs->trans("Security").'</td><td><select name="smtp_security">';
	print '<option value="none"'.($account->smtp_security == 'none' ? ' selected' : '').'>None</option>';
	print '<option value="ssl"'.($account->smtp_security == 'ssl' || !$account->smtp_security ? ' selected' : '').'>SSL/TLS</option>';
	print '<option value="starttls"'.($account->smtp_security == 'starttls' ? ' selected' : '').'>STARTTLS</option>';
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans("Login").'</td>';
	print '<td><input type="text" name="smtp_login" value="'.dol_escape_htmltag($account->smtp_login).'" size="40"></td></tr>';
	print '<tr><td>'.$langs->trans("Password").'</td>';
	print '<td><input type="password" name="smtp_password" size="40">'.($isEdit ? ' <span class="opacitymedium">'.$langs->trans("LeaveEmptyToKeep").'</span>' : '').'</td></tr>';

	// Sync limits
	print '<tr><td colspan="2" class="liste_titre">'.$langs->trans("InboxSyncLimits").'</td></tr>';
	print '<tr><td>'.$langs->trans("InboxSyncLimitNb").'</td>';
	print '<td><input type="number" name="sync_limit_nb" value="'.($account->sync_limit_nb ?: 500).'" size="6"> <span class="opacitymedium">'.$langs->trans("InboxSyncLimitNbDefault").'</span></td></tr>';
	print '<tr><td>'.$langs->trans("InboxSyncLimitDays").'</td>';
	print '<td><input type="number" name="sync_limit_days" value="'.($account->sync_limit_days ?: 180).'" size="6"> <span class="opacitymedium">'.$langs->trans("InboxSyncLimitDaysDefault").'</span></td></tr>';
	print '</tbody>';

	print '</table>';

	print '<script>
(function() {
	function toggleProviderSections() {
		var isWA = document.getElementById("ptype_wa").checked;
		document.getElementById("section_whatsapp").style.display = isWA ? "" : "none";
		document.getElementById("section_imap").style.display     = isWA ? "none" : "";
	}
	document.getElementById("ptype_imap").addEventListener("change", toggleProviderSections);
	document.getElementById("ptype_wa").addEventListener("change",   toggleProviderSections);

	// Auth-type toggle (inside IMAP section)
	document.querySelectorAll(\'input[name="auth_type"]\').forEach(function(r) {
		r.addEventListener("change", function() {
			var isOAuth = (this.value === "oauth2");
			document.getElementById("row_imap_password").style.display = isOAuth ? "none" : "";
			document.getElementById("row_oauth_service").style.display = isOAuth ? "" : "none";
			document.getElementById("row_oauth_link").style.display    = isOAuth ? "" : "none";
		});
	});
})();
</script>';

	print '<div class="center"><br>';
	print '<input type="submit" class="button button-save" value="'.($isEdit ? $langs->trans("Save") : $langs->trans("Add")).'">';
	if ($isEdit) {
		print ' &nbsp; <a href="'.$_SERVER["PHP_SELF"].'" class="button button-cancel">'.$langs->trans("Cancel").'</a>';
	}
	print '</div></form>';

} else {

	// ── Global settings ───────────────────────────────────────────────────────
	print '<br>';
	print load_fiche_titre($langs->trans("InboxGlobalSettings"), '', '');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="set_global">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans("InboxSendDelayLabel").'</td><td>';
	print '<input type="number" name="inbox_send_delay" value="'.getDolGlobalInt('INBOX_SEND_DELAY', 10).'" min="0" size="6">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxSendDelayHelp").'</span>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("InboxRefreshIntervalLabel").'</td><td>';
	print '<input type="number" name="inbox_refresh_interval" value="'.getDolGlobalInt('INBOX_REFRESH_INTERVAL', 0).'" min="0" size="6">';
	print ' <span class="opacitymedium">'.$langs->trans("InboxRefreshIntervalHelp").'</span>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("InboxBlockRemoteImagesLabel").'</td><td>';
	print '<input type="checkbox" name="inbox_block_remote_images" value="1"'.(getDolGlobalInt('INBOX_BLOCK_REMOTE_IMAGES', 1) ? ' checked' : '').'>';
	print ' <span class="opacitymedium">'.$langs->trans("InboxBlockRemoteImagesHelp").'</span>';
	print '</td></tr>';
	print '</table>';
	print '<div class="center"><br><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
	print '</form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
