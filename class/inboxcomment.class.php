<?php
/**
 *	\file       class/inboxcomment.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage internal comments attached to emails
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Stores internal (private) team comments tied to an email.
 *
 * An email is identified by its RFC 2822 Message-ID header when available
 * (stable across IMAP folder moves) or by (fk_account, message_uid) as a
 * fallback for rows created before Message-ID support was added.
 *
 * Soft deletion is used (status=0) so comments are never physically removed.
 */
class InboxComment extends CommonObject
{
	/** @var string  Dolibarr element identifier */
	public $element = 'inboxcomment';

	/** @var string  Database table name without llx_ prefix */
	public $table_element = 'inbox_comment';

	/** @var int     Row id */
	public $rowid;

	/** @var int     Dolibarr entity (multi-company) */
	public $entity;

	/** @var int     Foreign key to llx_inbox_account */
	public $fk_account;

	/** @var string  IMAP folder name at the time the comment was created (informational only) */
	public $folder;

	/** @var int     IMAP UID of the message at creation time */
	public $message_uid;

	/** @var string|null  RFC 2822 Message-ID header; preferred lookup key (stable across folder moves) */
	public $message_id;

	/** @var string  Comment body text */
	public $comment;

	/** @var int     1 = visible, 0 = soft-deleted */
	public $status;

	/** @var int     User id of the author */
	public $fk_user_creat;

	/** @var int|null  User id of the last modifier */
	public $fk_user_modif;

	/** @var string  Creation datetime (YYYY-MM-DD HH:MM:SS) */
	public $date_creation;

	/** @var string  Last modification timestamp (managed by MariaDB ON UPDATE) */
	public $tms;

	// Populated by fetchByMessage() via LEFT JOIN on llx_user
	/** @var string  Author login */
	public $user_login;
	/** @var string  Author first name */
	public $user_firstname;
	/** @var string  Author last name */
	public $user_lastname;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db  Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Insert a new comment row and return its id.
	 *
	 * All scalar properties (fk_account, folder, message_uid, message_id, comment,
	 * entity) must be set on $this before calling.
	 *
	 * @param  User $user  User performing the action (stored as fk_user_creat)
	 * @return int         Row id on success, -1 on SQL error ($this->error is set)
	 */
	public function create($user)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."inbox_comment
			(entity, fk_account, folder, message_uid, message_id, comment, status, fk_user_creat, date_creation)
			VALUES (
				".((int)$this->entity).",
				".((int)$this->fk_account).",
				'".$this->db->escape($this->folder)."',
				".((int)$this->message_uid).",
				".($this->message_id ? "'".$this->db->escape($this->message_id)."'" : "NULL").",
				'".$this->db->escape($this->comment)."',
				1,
				".((int)$user->id).",
				'".$this->db->idate(dol_now())."'
			)";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->rowid       = $this->db->last_insert_id(MAIN_DB_PREFIX.'inbox_comment');
		$this->fk_user_creat = $user->id;
		return $this->rowid;
	}

	/**
	 * Return all active comments for a given email, ordered chronologically.
	 *
	 * Lookup strategy:
	 * - If $message_id is non-empty, search by (fk_account, message_id, entity).
	 *   This survives IMAP folder moves because the RFC 2822 Message-ID is stable.
	 * - Otherwise fall back to (fk_account, message_uid, entity), which works for
	 *   legacy rows that pre-date Message-ID storage.
	 *
	 * Each returned object also carries user_login, user_firstname, user_lastname
	 * from a LEFT JOIN on llx_user.
	 *
	 * @param  int    $fk_account   Account row id
	 * @param  string $folder       Folder name (not used for filtering, kept for signature compat)
	 * @param  int    $message_uid  IMAP UID of the message
	 * @param  int    $entity       Dolibarr entity id
	 * @param  string $message_id   RFC 2822 Message-ID header (preferred; empty for legacy fallback)
	 * @return InboxComment[]       Array of InboxComment objects (may be empty)
	 */
	public function fetchByMessage($fk_account, $folder, $message_uid, $entity = 1, $message_id = '')
	{
		(void) $folder; // kept in signature for API compatibility; not used for filtering (see docblock)

		if (!empty($message_id)) {
			$where = "c.fk_account = ".((int)$fk_account)."
			  AND c.message_id = '".$this->db->escape($message_id)."'
			  AND c.entity = ".((int)$entity)."
			  AND c.status = 1";
		} else {
			$where = "c.fk_account = ".((int)$fk_account)."
			  AND c.message_uid = ".((int)$message_uid)."
			  AND c.entity = ".((int)$entity)."
			  AND c.status = 1";
		}

		$sql = "SELECT c.rowid, c.entity, c.fk_account, c.folder, c.message_uid, c.message_id,
				c.comment, c.status, c.fk_user_creat, c.fk_user_modif, c.date_creation, c.tms,
				u.login AS user_login, u.firstname AS user_firstname, u.lastname AS user_lastname
			FROM ".MAIN_DB_PREFIX."inbox_comment c
			LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = c.fk_user_creat
			WHERE ".$where."
			ORDER BY c.date_creation ASC";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$list = array();
		while ($obj = $this->db->fetch_object($res)) {
			$c = new InboxComment($this->db);
			$c->rowid         = $obj->rowid;
			$c->entity        = $obj->entity;
			$c->fk_account    = $obj->fk_account;
			$c->folder        = $obj->folder;
			$c->message_uid   = $obj->message_uid;
			$c->message_id    = $obj->message_id;
			$c->comment       = $obj->comment;
			$c->status        = $obj->status;
			$c->fk_user_creat = $obj->fk_user_creat;
			$c->date_creation = $obj->date_creation;
			$c->user_login     = $obj->user_login;
			$c->user_firstname = $obj->user_firstname;
			$c->user_lastname  = $obj->user_lastname;
			$list[] = $c;
		}
		return $list;
	}

	/**
	 * Soft-delete a comment by setting status = 0.
	 *
	 * Only the comment's author or an admin may delete it.
	 * Returns -2 (rather than -1) when the row exists but the caller lacks permission.
	 *
	 * @param  User $user   User performing the deletion
	 * @param  int  $rowid  Comment row id to delete
	 * @return int          1 on success, -1 on SQL error, -2 if permission denied
	 */
	public function deleteComment($user, $rowid)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."inbox_comment
			SET status = 0, fk_user_modif = ".((int)$user->id)."
			WHERE rowid = ".((int)$rowid)."
			  AND (fk_user_creat = ".((int)$user->id)." OR ".((int)$user->admin)." = 1)";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $this->db->affected_rows($res) > 0 ? 1 : -2;
	}
}
