<?php
/**
 *	\file       index.php
 *	\ingroup    inbox
 *	\brief      Main page for Inbox module
 */

$res = 0;
if (!defined('FORCE_CKEDITOR')) define('FORCE_CKEDITOR', 1);
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../main.inc.php';
}
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $conf, $db;

$langs->loadLangs(array("inbox@inbox"));

// Access control
if (empty($user->rights->inbox->read)) {
	accessforbidden();
}

$page_name = "Inbox";
$head = '';
$head .= '<link rel="stylesheet" type="text/css" href="'.DOL_URL_ROOT.'/custom/inbox/css/inbox.css">';
$head .= '<script type="module" src="'.dol_buildpath('/inbox/js/app.js', 2).'"></script>';

llxHeader($head, $langs->trans($page_name));

?>

<div class="inbox-container">
	<!-- Panel 1: Mailboxes / Folders -->
	<div class="inbox-panel inbox-sidebar" id="panel-folders">
		<div class="inbox-panel-header">
			<button class="btn-new-message"><i class="fa fa-edit"></i><span class="btn-label"> <?php echo $langs->trans('InboxNewMessage'); ?></span></button>
			<button class="btn-sidebar-toggle btn-icon" id="btn-sidebar-toggle" title="Réduire la barre latérale"><i class="fa fa-chevron-left"></i></button>
		</div>
		<div class="inbox-panel-content">
			<div class="mailbox-section">
				<h3><?php echo $langs->trans('InboxMailboxes'); ?></h3>
				<ul class="folder-list" id="dynamic-folder-list">
					<li class="active"><i class="fa fa-spin fa-spinner"></i> Chargement...</li>
				</ul>
			</div>
		</div>
	</div>

	<!-- Panel 2: Email List -->
	<div class="inbox-panel inbox-list" id="panel-list">
		<div class="inbox-panel-header">
			<input type="text" placeholder="<?php echo dol_escape_htmltag($langs->trans('InboxSearchPlaceholder')); ?>" class="inbox-search">
			<button class="btn-icon"><i class="fa fa-sync"></i></button>
		</div>
		<div class="inbox-panel-content" id="email-list-container">
			<!-- Email items injected via JS -->
		</div>
	</div>

	<!-- Panel 3: Email View -->
	<div class="inbox-panel inbox-view" id="panel-view" style="display:none;">
		<div class="inbox-panel-header">
			<div class="header-actions">
				<button class="btn-icon"><i class="fa fa-trash"></i></button>
				<button class="btn-icon"><i class="fa fa-archive"></i></button>
				<button class="btn-icon"><i class="fa fa-reply"></i></button>
			</div>
		</div>
		<div class="inbox-panel-content">
			<div class="email-view-header">
				<h2 class="email-view-subject"></h2>
				<div class="email-view-tags" id="email-view-tags">
					<!-- Tags injected by JS -->
					<div class="tag-picker-wrapper" id="tag-picker-wrapper" style="display:none;">
						<div class="tag-picker" id="tag-picker">
							<!-- Options injected by JS -->
						</div>
					</div>
					<button class="btn-add-tag" id="btn-add-tag"><i class="fa fa-plus"></i> Tag</button>
				</div>
			</div>
			<div class="email-view-sender-info">
				<div class="sender-avatar"></div>
				<div class="sender-details">
					<div class="sender-name"></div>
					<div class="sender-email"></div>
				</div>
				<div class="email-view-date"></div>
			</div>

			<div id="remote-images-banner" class="remote-images-banner" style="display:none;">
				<i class="fa fa-eye-slash"></i>
				<span><?php echo $langs->trans('InboxRemoteImagesBlocked'); ?></span>
				<button id="btn-show-images" class="btn-show-images"><?php echo $langs->trans('InboxShowImages'); ?></button>
			</div>

			<div class="email-view-body"></div>

			<div id="reply-form-container" style="display: none; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
				<h3 style="margin-bottom: 15px; font-size: 1.1em; color: #1e293b;"><?php echo $langs->trans('InboxReply'); ?></h3>
				<div style="margin-bottom: 10px;">
					<label style="display:inline-block; width: 60px; font-weight: 500; color:#64748b;"><?php echo $langs->trans('InboxTo'); ?></label>
					<input type="text" id="reply-to" style="width: calc(100% - 70px); padding: 5px; border: 1px solid #cbd5e1; border-radius: 3px;">
				</div>
				<div style="margin-bottom: 10px;">
					<label style="display:inline-block; width: 60px; font-weight: 500; color:#64748b;"><?php echo $langs->trans('InboxCc'); ?></label>
					<input type="text" id="reply-cc" style="width: calc(100% - 70px); padding: 5px; border: 1px solid #cbd5e1; border-radius: 3px;">
				</div>
				<div style="margin-bottom: 15px;">
					<label style="display:inline-block; width: 60px; font-weight: 500; color:#64748b;"><?php echo $langs->trans('InboxSubject'); ?></label>
					<input type="text" id="reply-subject" style="width: calc(100% - 70px); padding: 5px; border: 1px solid #cbd5e1; border-radius: 3px;">
				</div>

				<!-- CKEditor Textarea -->
				<textarea id="replybody" name="replybody"></textarea>

				<div style="margin-top: 15px; text-align: right;">
					<button class="btn-primary" id="btn-cancel-reply" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 8px 16px; margin-right: 10px; cursor: pointer;"><?php echo $langs->trans('Cancel'); ?></button>
					<button class="btn-primary" id="btn-send-reply" style="background: #2563eb; color: white; border: none; padding: 8px 16px; border-radius: 3px; cursor: pointer;"><?php echo $langs->trans('InboxSend'); ?> <i class="fa fa-paper-plane" style="margin-left: 5px;"></i></button>
				</div>
			</div>
		</div>
	</div>

	<!-- Panel 4: Context / ERP Linking -->
	<div class="inbox-panel inbox-context" id="panel-context">
		<div class="inbox-panel-header">
			<h3><?php echo $langs->trans('InboxErpContext'); ?></h3>
		</div>
		<div class="inbox-panel-content">
			<div class="context-section">
				<h4><?php echo $langs->trans('InboxLinkedDocuments'); ?></h4>
				<div class="linked-items">
				</div>
				<input type="text" placeholder="<?php echo dol_escape_htmltag($langs->trans('InboxLinkDocumentPlaceholder')); ?>" class="context-search">
			</div>

			<div class="context-section comments-section">
				<h4><?php echo $langs->trans('InboxInternalComments'); ?> <span class="badge">0</span></h4>
				<div class="comments-list">
				</div>
				<div class="comment-input-area">
					<textarea placeholder="<?php echo dol_escape_htmltag($langs->trans('InboxAddComment')); ?>"></textarea>
					<button class="btn-primary"><?php echo $langs->trans('InboxSend'); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	var inboxSendDelay = <?php echo getDolGlobalInt('INBOX_SEND_DELAY', 10); ?>;
	var inboxRefreshInterval = <?php echo getDolGlobalInt('INBOX_REFRESH_INTERVAL', 0); ?>;
</script>
<?php
llxFooter();
$db->close();
