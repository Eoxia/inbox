<?php
/**
 *	\file       class/inboxtag.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage inbox tags
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * A tag (label) that can be applied to emails.
 * Optionally maps to an IMAP keyword so the tag is synced server-side
 * and visible in other mail clients.
 */
class InboxTag extends CommonObject
{
	/** @var string  Dolibarr element identifier */
	public $element = 'inboxtag';

	/** @var string  Database table name without llx_ prefix */
	public $table_element = 'inbox_tag';

	/** @var int     Row id */
	public $rowid;

	/** @var int     Dolibarr entity */
	public $entity;

	/** @var string  Display label (max 50 chars) */
	public $label;

	/** @var string  CSS hex color, e.g. "#3b82f6" */
	public $color;

	/** @var string|null  IMAP keyword to sync with (NULL = Dolibarr-only tag) */
	public $imap_keyword;

	/** @var int     Sort order */
	public $position;

	/** @var int     1 = active, 0 = deleted */
	public $status;

	/** @var int     Creator user id */
	public $fk_user_creat;

	/** @var string  Creation datetime */
	public $date_creation;

	/** @var string  Last modification timestamp */
	public $tms;

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
	 * Return all active tags for an entity, sorted by position then label.
	 *
	 * @param  int   $entity  Dolibarr entity id
	 * @return InboxTag[]     Array of InboxTag objects (may be empty)
	 */
	public function fetchAll($entity = 1)
	{
		$sql = "SELECT rowid, entity, label, color, imap_keyword, position, status, fk_user_creat, date_creation, tms
			FROM ".MAIN_DB_PREFIX."inbox_tag
			WHERE entity = ".((int)$entity)."
			  AND status = 1
			ORDER BY position ASC, label ASC";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$list = array();
		while ($obj = $this->db->fetch_object($res)) {
			$t = new InboxTag($this->db);
			$t->rowid        = (int) $obj->rowid;
			$t->entity       = (int) $obj->entity;
			$t->label        = $obj->label;
			$t->color        = $obj->color;
			$t->imap_keyword = $obj->imap_keyword;
			$t->position     = (int) $obj->position;
			$t->status       = (int) $obj->status;
			$t->fk_user_creat = (int) $obj->fk_user_creat;
			$t->date_creation = $obj->date_creation;
			$list[] = $t;
		}
		return $list;
	}

	/**
	 * Load a single tag by id.
	 *
	 * @param  int $id  Row id
	 * @return int      1 if found, 0 if not found, -1 on SQL error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, label, color, imap_keyword, position, status, fk_user_creat, date_creation, tms
			FROM ".MAIN_DB_PREFIX."inbox_tag
			WHERE rowid = ".((int)$id);

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($res) == 0) return 0;

		$obj = $this->db->fetch_object($res);
		$this->rowid        = (int) $obj->rowid;
		$this->entity       = (int) $obj->entity;
		$this->label        = $obj->label;
		$this->color        = $obj->color;
		$this->imap_keyword = $obj->imap_keyword;
		$this->position     = (int) $obj->position;
		$this->status       = (int) $obj->status;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->date_creation = $obj->date_creation;
		return 1;
	}

	/**
	 * Insert a new tag row.
	 *
	 * @param  User $user  Creator
	 * @return int         Row id on success, -1 on error
	 */
	public function create($user)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."inbox_tag
			(entity, label, color, imap_keyword, position, status, fk_user_creat, date_creation)
			VALUES (
				".((int)$this->entity).",
				'".$this->db->escape($this->label)."',
				'".$this->db->escape($this->color ?: '#3b82f6')."',
				".($this->imap_keyword ? "'".$this->db->escape($this->imap_keyword)."'" : "NULL").",
				".((int)$this->position).",
				1,
				".((int)$user->id).",
				'".$this->db->idate(dol_now())."'
			)";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX.'inbox_tag');
		return $this->rowid;
	}

	/**
	 * Update label, color and imap_keyword of an existing tag.
	 *
	 * @param  User $user  User performing the update
	 * @return int         1 on success, -1 on error
	 */
	public function update($user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."inbox_tag SET
			label        = '".$this->db->escape($this->label)."',
			color        = '".$this->db->escape($this->color ?: '#3b82f6')."',
			imap_keyword = ".($this->imap_keyword ? "'".$this->db->escape($this->imap_keyword)."'" : "NULL").",
			position     = ".((int)$this->position)."
			WHERE rowid = ".((int)$this->rowid);

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Soft-delete a tag (sets status = 0).
	 *
	 * @param  User $user  User performing the deletion
	 * @return int         1 on success, -1 on error
	 */
	public function delete($user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."inbox_tag
			SET status = 0
			WHERE rowid = ".((int)$this->rowid);

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
