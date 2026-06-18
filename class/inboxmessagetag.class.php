<?php
/**
 *	\file       class/inboxmessagetag.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage tag associations on emails
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Links an InboxTag to an email identified by its RFC 2822 Message-ID.
 * The message_id is used as primary key for lookups (stable across folder moves).
 */
class InboxMessageTag extends CommonObject
{
	/** @var string  Dolibarr element identifier */
	public $element = 'inboxmessagetag';

	/** @var string  Database table name without llx_ prefix */
	public $table_element = 'inbox_message_tag';

	/** @var int     Row id */
	public $rowid;

	/** @var int     Dolibarr entity */
	public $entity;

	/** @var int     Foreign key to llx_inbox_tag */
	public $fk_tag;

	/** @var int     Foreign key to llx_inbox_account */
	public $fk_account;

	/** @var string  RFC 2822 Message-ID (stable identifier across folder moves) */
	public $message_id;

	/** @var int|null  IMAP UID at time of tagging (informational) */
	public $message_uid;

	/** @var string|null  IMAP folder at time of tagging (informational) */
	public $folder;

	/** @var int     Creator user id */
	public $fk_user_creat;

	/** @var string  Creation datetime */
	public $date_creation;

	// Populated by fetchByMessage() via JOIN on llx_inbox_tag
	/** @var string  Tag label */
	public $tag_label;
	/** @var string  Tag CSS hex color */
	public $tag_color;
	/** @var string|null  Tag IMAP keyword */
	public $tag_imap_keyword;

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
	 * Return all tag associations for a given email, with tag metadata joined.
	 *
	 * @param  int    $fk_account  Account row id
	 * @param  string $message_id  RFC 2822 Message-ID
	 * @param  int    $entity      Dolibarr entity id
	 * @return InboxMessageTag[]   Array of objects (tag_label/color/imap_keyword populated)
	 */
	public function fetchByMessage($fk_account, $message_id, $entity = 1)
	{
		$sql = "SELECT mt.rowid, mt.entity, mt.fk_tag, mt.fk_account, mt.message_id,
				mt.message_uid, mt.folder, mt.fk_user_creat, mt.date_creation,
				t.label AS tag_label, t.color AS tag_color, t.imap_keyword AS tag_imap_keyword
			FROM ".MAIN_DB_PREFIX."inbox_message_tag mt
			INNER JOIN ".MAIN_DB_PREFIX."inbox_tag t ON t.rowid = mt.fk_tag AND t.status = 1
			WHERE mt.fk_account = ".((int)$fk_account)."
			  AND mt.message_id = '".$this->db->escape($message_id)."'
			  AND mt.entity = ".((int)$entity)."
			ORDER BY t.position ASC, t.label ASC";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$list = array();
		while ($obj = $this->db->fetch_object($res)) {
			$mt = new InboxMessageTag($this->db);
			$mt->rowid            = (int) $obj->rowid;
			$mt->entity           = (int) $obj->entity;
			$mt->fk_tag           = (int) $obj->fk_tag;
			$mt->fk_account       = (int) $obj->fk_account;
			$mt->message_id       = $obj->message_id;
			$mt->message_uid      = $obj->message_uid;
			$mt->folder           = $obj->folder;
			$mt->fk_user_creat    = (int) $obj->fk_user_creat;
			$mt->date_creation    = $obj->date_creation;
			$mt->tag_label        = $obj->tag_label;
			$mt->tag_color        = $obj->tag_color;
			$mt->tag_imap_keyword = $obj->tag_imap_keyword;
			$list[] = $mt;
		}
		return $list;
	}

	/**
	 * Associate a tag with an email (INSERT IGNORE on duplicate).
	 *
	 * @param  int         $fk_tag      Tag row id
	 * @param  int         $fk_account  Account row id
	 * @param  string      $message_id  RFC 2822 Message-ID
	 * @param  int|null    $message_uid IMAP UID (informational)
	 * @param  string|null $folder      IMAP folder (informational)
	 * @param  int         $entity      Dolibarr entity id
	 * @param  User        $user        User performing the action
	 * @return int                      Row id on success, -1 on error, 0 if already exists
	 */
	public function addTag($fk_tag, $fk_account, $message_id, $message_uid, $folder, $entity, $user)
	{
		$sql = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."inbox_message_tag
			(entity, fk_tag, fk_account, message_id, message_uid, folder, fk_user_creat, date_creation)
			VALUES (
				".((int)$entity).",
				".((int)$fk_tag).",
				".((int)$fk_account).",
				'".$this->db->escape($message_id)."',
				".($message_uid ? (int)$message_uid : "NULL").",
				".($folder ? "'".$this->db->escape($folder)."'" : "NULL").",
				".((int)$user->id).",
				'".$this->db->idate(dol_now())."'
			)";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$affected = $this->db->affected_rows($res);
		if ($affected == 0) return 0; // already existed
		return $this->db->last_insert_id(MAIN_DB_PREFIX.'inbox_message_tag');
	}

	/**
	 * Remove a specific tag from an email.
	 *
	 * @param  int    $fk_tag      Tag row id
	 * @param  int    $fk_account  Account row id
	 * @param  string $message_id  RFC 2822 Message-ID
	 * @return int                 1 on success, -1 on error
	 */
	public function removeTag($fk_tag, $fk_account, $message_id)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."inbox_message_tag
			WHERE fk_tag    = ".((int)$fk_tag)."
			  AND fk_account = ".((int)$fk_account)."
			  AND message_id = '".$this->db->escape($message_id)."'";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
