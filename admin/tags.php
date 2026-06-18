<?php
/**
 *	\file       admin/tags.php
 *	\ingroup    inbox
 *	\brief      Admin page to manage inbox tags
 */

$res = 0;
if (!($res && preg_match('/^http/', $res))) $res = @include '../../main.inc.php';
if (!($res && preg_match('/^http/', $res))) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/inboxtag.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/lib/inbox.lib.php';

global $db, $user, $conf, $langs;

$langs->loadLangs(array("inbox@inbox", "admin"));

if (empty($user->rights->inbox->setup)) accessforbidden();

$action = GETPOST('action', 'aZ09');
$rowid  = (int) GETPOST('rowid', 'int');

/**
 * Validate and sanitize a hex color string.
 *
 * @param  string $raw     Raw value from GETPOST
 * @param  string $default Fallback color
 * @return string          Validated hex color
 */
function inboxSanitizeColor($raw, $default = '#3b82f6')
{
	$c = trim((string) $raw);
	return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $default;
}

// ── Actions ──────────────────────────────────────────────────────────────────

if ($action === 'add') {
	$label   = trim(GETPOST('label', 'alphanohtml'));
	$color   = inboxSanitizeColor(GETPOST('color', 'none'));
	$keyword = trim(GETPOST('imap_keyword', 'aZ09'));

	if ($label) {
		$t = new InboxTag($db);
		$t->entity       = $conf->entity;
		$t->label        = $label;
		$t->color        = $color;
		$t->imap_keyword = $keyword ?: null;
		$t->position     = 0;
		if ($t->create($user) < 0) {
			setEventMessages($t->error, null, 'errors');
		} else {
			setEventMessages($langs->trans("InboxTagCreated"), null, 'mesgs');
		}
	}
}

if ($action === 'update' && $rowid) {
	$t = new InboxTag($db);
	$t->fetch($rowid);
	$t->label        = trim(GETPOST('label', 'alphanohtml'));
	$t->color        = inboxSanitizeColor(GETPOST('color', 'none'));
	$keyword         = trim(GETPOST('imap_keyword', 'aZ09'));
	$t->imap_keyword = $keyword ?: null;
	if ($t->update($user) < 0) {
		setEventMessages($t->error, null, 'errors');
	} else {
		setEventMessages($langs->trans("InboxTagUpdated"), null, 'mesgs');
	}
}

if ($action === 'delete' && $rowid) {
	$t = new InboxTag($db);
	$t->rowid = $rowid;
	if ($t->delete($user) < 0) {
		setEventMessages($t->error, null, 'errors');
	} else {
		setEventMessages($langs->trans("InboxTagDeleted"), null, 'mesgs');
	}
}

// ── View ─────────────────────────────────────────────────────────────────────

llxHeader('', $langs->trans("InboxTags"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("InboxSetup"), $linkback, 'title_setup');

$head = adminInboxPrepareHead();
print dol_get_fiche_head($head, 'tags', '', -1, 'fa-envelope');

// Edit form (shown when action=edit)
$editing = null;
if ($action === 'edit' && $rowid) {
	$editing = new InboxTag($db);
	$editing->fetch($rowid);
}

// ── Create / Edit form ───────────────────────────────────────────────────────
print '<div class="div-table-responsive-no-min" style="max-width:600px; margin-bottom:30px;">';
print '<form method="POST" action="'.DOL_URL_ROOT.'/custom/inbox/admin/tags.php">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($editing ? 'update' : 'add').'">';
if ($editing) print '<input type="hidden" name="rowid" value="'.$editing->rowid.'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.($editing ? $langs->trans("InboxEditTag") : $langs->trans("InboxNewTag")).'</th></tr>';

$lbl = $editing ? dol_escape_htmltag($editing->label)        : '';
$col = $editing ? dol_escape_htmltag($editing->color)        : '#3b82f6';
$kw  = $editing ? dol_escape_htmltag($editing->imap_keyword) : '';

print '<tr class="oddeven"><td style="width:180px">'.$langs->trans("InboxTagLabel").' <span style="color:red">*</span></td>';
print '<td><input type="text" name="label" value="'.$lbl.'" maxlength="50" required style="width:200px;"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("InboxColor").'</td>';
print '<td><input type="color" name="color" value="'.$col.'" style="height:36px;width:60px;padding:2px;border:1px solid #ccc;border-radius:4px;cursor:pointer;"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("InboxImapKeyword").'</td>';
print '<td><input type="text" name="imap_keyword" value="'.$kw.'" maxlength="50" placeholder="'.dol_escape_htmltag($langs->trans("InboxImapKeywordPlaceholder")).'" style="width:200px;">';
print '<div style="font-size:0.82em;color:#64748b;margin-top:3px;">'.$langs->trans("InboxImapKeywordHelp").'</div></td></tr>';

print '<tr><td></td><td style="padding-top:10px;">';
print '<button type="submit" class="butAction">'.($editing ? $langs->trans("Save") : $langs->trans("Create")).'</button>';
if ($editing) print ' <a href="'.DOL_URL_ROOT.'/custom/inbox/admin/tags.php" class="butActionDelete" style="margin-left:8px;">'.$langs->trans("Cancel").'</a>';
print '</td></tr>';
print '</table></form></div>';

// ── Tag list ─────────────────────────────────────────────────────────────────
$tagObj = new InboxTag($db);
$tags   = $tagObj->fetchAll($conf->entity);

print '<table class="noborder centpercent" style="max-width:700px;">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("InboxTags").'</th><th>'.$langs->trans("InboxImapKeyword").'</th><th style="text-align:center;">'.$langs->trans("Actions").'</th>';
print '</tr>';

if (empty($tags)) {
	print '<tr class="oddeven"><td colspan="3" style="text-align:center;color:#64748b;">'.$langs->trans("InboxNoTagDefined").'</td></tr>';
}

foreach ($tags as $t) {
	print '<tr class="oddeven">';
	print '<td><span style="display:inline-flex;align-items:center;gap:6px;">';
	print '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:'.dol_escape_htmltag($t->color).';"></span>';
	print dol_escape_htmltag($t->label);
	print '</span></td>';
	print '<td><code style="font-size:0.85em;">'.($t->imap_keyword ? dol_escape_htmltag($t->imap_keyword) : '<em style="color:#94a3b8;">—</em>').'</code></td>';
	print '<td style="text-align:center;">';
	print '<a href="'.DOL_URL_ROOT.'/custom/inbox/admin/tags.php?action=edit&rowid='.$t->rowid.'" class="butAction" style="margin-right:5px;">'.$langs->trans("Modify").'</a>';
	print '<form method="POST" action="'.DOL_URL_ROOT.'/custom/inbox/admin/tags.php" style="display:inline;" onsubmit="return confirm(\''.dol_escape_js($langs->trans("InboxConfirmDeleteTag")).'\');">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="delete"><input type="hidden" name="rowid" value="'.$t->rowid.'">';
	print '<button type="submit" class="butActionDelete">'.$langs->trans("Delete").'</button>';
	print '</form>';
	print '</td></tr>';
}

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
