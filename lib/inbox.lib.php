<?php
/**
 *	\file       lib/inbox.lib.php
 *	\ingroup    inbox
 *	\brief      Library with common functions for Inbox module
 */

/**
 * Prepare array of tabs for Inbox admin pages
 *
 * @return array<array{string,string,string}>
 */
function adminInboxPrepareHead()
{
	global $langs, $conf;

	$langs->load("inbox@inbox");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildurl(dol_buildpath('/inbox/admin/setup.php', 1));
	$head[$h][1] = $langs->trans("InboxAccounts");
	$head[$h][2] = 'accounts';
	$h++;

	$head[$h][0] = dolBuildurl(dol_buildpath('/inbox/admin/tags.php', 1));
	$head[$h][1] = $langs->trans("InboxTags");
	$head[$h][2] = 'tags';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'inbox@inbox');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'inbox@inbox', 'remove');

	return $head;
}
