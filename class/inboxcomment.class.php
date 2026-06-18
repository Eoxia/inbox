<?php
/**
 *	\file       class/inboxcomment.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage internal comments on emails
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class InboxComment extends CommonObject
{
	public $element       = 'inboxcomment';
	public $table_element = 'inbox_comment';

	public $rowid;
	public $entity;
	public $fk_account;
	public $folder;
	public $message_uid;
	public $message_id;
	public $comment;
	public $status;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $tms;

	// Populated on fetch (joined from llx_user)
	public $user_login;
	public $user_firstname;
	public $user_lastname;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create a comment
	 * @param  User $user
	 * @return int  rowid if OK, <0 if KO
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
	 * Fetch all active comments for an email, newest last.
	 * Searches by message_id when provided (stable across folder moves),
	 * falls back to (fk_account, message_uid) for legacy rows without message_id.
	 *
	 * @param  int    $fk_account
	 * @param  string $folder       Stored for info only, not used for filtering
	 * @param  int    $message_uid
	 * @param  int    $entity
	 * @param  string $message_id   RFC 2822 Message-ID header (preferred key)
	 * @return array  of InboxComment objects, or empty array
	 */
	public function fetchByMessage($fk_account, $folder, $message_uid, $entity = 1, $message_id = '')
	{
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
	 * Soft-delete a comment (sets status=0)
	 * @param  User $user
	 * @param  int  $rowid
	 * @return int  1 if OK, <0 if KO
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
