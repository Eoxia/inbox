<?php
/**
 *	\file       index.php
 *	\ingroup    inbox
 *	\brief      Main page for Inbox module
 */

$res = 0;
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
$head .= '<script type="module" src="'.DOL_URL_ROOT.'/custom/inbox/js/app.js"></script>';

llxHeader($head, $langs->trans($page_name));

?>

<div class="inbox-container">
	<!-- Panel 1: Mailboxes / Folders -->
	<div class="inbox-panel inbox-sidebar" id="panel-folders">
		<div class="inbox-panel-header">
			<button class="btn-new-message">+ Nouveau message</button>
		</div>
		<div class="inbox-panel-content">
			<div class="mailbox-section">
				<h3>MAILBOXES</h3>
				<ul class="folder-list">
					<li class="active"><i class="fa fa-inbox"></i> Inbox <span class="badge">12</span></li>
					<li><i class="fa fa-paper-plane"></i> Sent</li>
					<li><i class="fa fa-file"></i> Drafts <span class="badge">2</span></li>
					<li><i class="fa fa-trash"></i> Trash <span class="badge">5</span></li>
				</ul>
			</div>
		</div>
	</div>

	<!-- Panel 2: Email List -->
	<div class="inbox-panel inbox-list" id="panel-list">
		<div class="inbox-panel-header">
			<input type="text" placeholder="Search mail..." class="inbox-search">
			<button class="btn-icon"><i class="fa fa-sync"></i></button>
		</div>
		<div class="inbox-panel-content" id="email-list-container">
			<!-- Email items injected via JS -->
			<div class="email-item unread active">
				<div class="email-item-header">
					<span class="email-sender">Dolibarr Sales</span>
					<span class="email-date">31 mars</span>
				</div>
				<div class="email-subject">Project Update</div>
				<div class="email-tags">
					<span class="tag tag-blue">Projet</span>
					<span class="tag tag-red">Urgent</span>
				</div>
				<div class="email-snippet">Hi John, just wanted to give you a quick update on the project...</div>
				<div class="email-meta"><i class="fa fa-comment"></i> 2 commentaires</div>
			</div>
		</div>
	</div>

	<!-- Panel 3: Email View -->
	<div class="inbox-panel inbox-view" id="panel-view">
		<div class="inbox-panel-header">
			<div class="header-actions">
				<button class="btn-icon"><i class="fa fa-trash"></i></button>
				<button class="btn-icon"><i class="fa fa-archive"></i></button>
				<button class="btn-icon"><i class="fa fa-reply"></i></button>
			</div>
		</div>
		<div class="inbox-panel-content">
			<div class="email-view-header">
				<h2 class="email-view-subject">Project Update</h2>
				<div class="email-view-tags">
					<span class="tag tag-blue">Projet</span>
					<span class="tag tag-red">Urgent</span>
					<button class="btn-add-tag"><i class="fa fa-plus"></i> Ajouter un tag</button>
				</div>
			</div>
			<div class="email-view-sender-info">
				<div class="sender-avatar">D</div>
				<div class="sender-details">
					<div class="sender-name">Dolibarr Sales <a href="#" class="link-erp"><i class="fa fa-user"></i> Fiche Contact ERP</a></div>
					<div class="sender-email">À: sales@dolibarr.org</div>
				</div>
				<div class="email-view-date">31 mars 2026, 11:00</div>
			</div>
			
			<div class="email-view-body">
				<p>Hi John, just wanted to give you a quick update on the project. Everything is on track for the Friday deadline.</p>
			</div>
		</div>
	</div>

	<!-- Panel 4: Context / ERP Linking -->
	<div class="inbox-panel inbox-context" id="panel-context">
		<div class="inbox-panel-header">
			<h3>Contexte ERP</h3>
		</div>
		<div class="inbox-panel-content">
			<div class="context-section">
				<h4>Pièces liées</h4>
				<div class="linked-items">
					<div class="linked-item tag-orange">FC-023121 <i class="fa fa-times"></i></div>
					<div class="linked-item tag-green">CDE2606018 <i class="fa fa-times"></i></div>
				</div>
				<input type="text" placeholder="Lier Facture, Devis..." class="context-search">
			</div>

			<div class="context-section comments-section">
				<h4>Commentaires internes <span class="badge">2</span></h4>
				<div class="comments-list">
					<div class="comment-item">
						<div class="comment-avatar">AB</div>
						<div class="comment-content">
							<div class="comment-meta"><span class="comment-author">Alice Berthelot</span> <span class="comment-date">31/03/2026 13:00</span></div>
							<div class="comment-text">"Superbe réactivité de l'équipe commerciale ! Je valide de mon côté."</div>
						</div>
					</div>
				</div>
				<div class="comment-input-area">
					<textarea placeholder="Ajouter un commentaire..."></textarea>
					<button class="btn-primary">Envoyer</button>
				</div>
			</div>
		</div>
	</div>
</div>

<?php
llxFooter();
$db->close();
