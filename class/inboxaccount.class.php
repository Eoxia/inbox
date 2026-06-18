<?php
/**
 *	\file       class/inboxaccount.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage email accounts (IMAP + SMTP settings)
 */

require_once DOL_DOCUMENT_ROOT .'/core/class/commonobject.class.php';

/**
 * Stores IMAP and SMTP connection parameters for one mailbox.
 * A shared account (shared=1) is visible to all internal users.
 * A personal account (fk_user set) is only offered to its owner.
 */
class InboxAccount extends CommonObject
{
	/** @var string  Dolibarr element identifier */
	public $element = 'inboxaccount';

	/** @var string  Database table name without llx_ prefix */
	public $table_element = 'inbox_account';

	/** @var int     Row id (alias of $id for legacy access) */
	public $rowid;

	/** @var string  Human-readable label for this account */
	public $label;

	/** @var string  Sender email address */
	public $email;

	/** @var string  IMAP server hostname or IP */
	public $imap_server;

	/** @var int     IMAP port (typically 993 for SSL, 143 otherwise) */
	public $imap_port;

	/** @var string  IMAP transport security: 'ssl', 'tls', or 'none' */
	public $imap_security;

	/** @var string  IMAP login (often the email address) */
	public $imap_login;

	/** @var string  IMAP password (stored encrypted at rest) */
	public $imap_password;

	/** @var string  SMTP server hostname or IP */
	public $smtp_server;

	/** @var int     SMTP port (typically 465/587) */
	public $smtp_port;

	/** @var string  SMTP transport security: 'ssl', 'tls', or 'none' */
	public $smtp_security;

	/** @var string  SMTP login */
	public $smtp_login;

	/** @var string  SMTP password */
	public $smtp_password;

	/** @var int     1 = accept self-signed TLS certificates */
	public $allow_self_signed;

	/** @var string  HTML signature appended to outgoing messages */
	public $signature;

	/** @var int|null  Owner user id; NULL means shared across all users */
	public $fk_user;

	/** @var int     Maximum number of messages to fetch in one sync */
	public $sync_limit_nb = 500;

	/** @var int     Only fetch messages newer than this many days */
	public $sync_limit_days = 180;

	/** @var int     1 = account is visible to all internal users */
	public $shared;

	/** @var int     1 = active, 0 = disabled */
	public $status;

	/** @var string  Creation date (YYYY-MM-DD HH:MM:SS) */
	public $date_creation;

	/** @var string  Last modification timestamp (managed by MariaDB ON UPDATE) */
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
	 * Insert a new account row into the database.
	 *
	 * @param  User $user       User performing the action (for audit trail)
	 * @param  int  $notrigger  0 = fire triggers, 1 = skip triggers
	 * @return int              Row id on success, -1 on failure ($this->errors is populated)
	 */
	public function create($user, $notrigger = 0)
	{
		$error = 0;

		$this->db->begin();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."inbox_account (";
		$sql .= "label, email, imap_server, imap_port, imap_security, imap_login, imap_password, ";
		$sql .= "smtp_server, smtp_port, smtp_security, smtp_login, smtp_password, allow_self_signed, signature, ";
		$sql .= "fk_user, sync_limit_nb, sync_limit_days, shared, status, date_creation";
		$sql .= ") VALUES (";
		$sql .= "'".$this->db->escape($this->label)."',";
		$sql .= "'".$this->db->escape($this->email)."',";
		$sql .= "'".$this->db->escape($this->imap_server)."',";
		$sql .= (int) $this->imap_port.",";
		$sql .= "'".$this->db->escape($this->imap_security)."',";
		$sql .= "'".$this->db->escape($this->imap_login)."',";
		$sql .= "'".$this->db->escape($this->imap_password)."',";
		$sql .= "'".$this->db->escape($this->smtp_server)."',";
		$sql .= (int) $this->smtp_port.",";
		$sql .= "'".$this->db->escape($this->smtp_security)."',";
		$sql .= "'".$this->db->escape($this->smtp_login)."',";
		$sql .= "'".$this->db->escape($this->smtp_password)."',";
		$sql .= (int) $this->allow_self_signed.",";
		$sql .= "'".$this->db->escape($this->signature)."',";
		$sql .= $this->fk_user > 0 ? $this->fk_user : "NULL";
		$sql .= ",".(int) $this->sync_limit_nb.",";
		$sql .= (int) $this->sync_limit_days.",";
		$sql .= (int) $this->shared.",";
		$sql .= (int) $this->status.",";
		$sql .= "'".$this->db->idate(dol_now())."'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."inbox_account");
			$this->rowid = $this->id;
		} else {
			$error++;
			$this->errors[] = "Error ".$this->db->lasterror();
		}

		if (!$error) {
			$this->db->commit();
			return $this->id;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Load an account record from the database into this object.
	 *
	 * @param  int $id  Row id to load
	 * @return int      1 if found, 0 if not found, -1 on SQL error
	 */
	public function fetch($id)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."inbox_account WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->id = $obj->rowid;
				$this->rowid = $obj->rowid;
				$this->label = $obj->label;
				$this->email = $obj->email;
				$this->imap_server = $obj->imap_server;
				$this->imap_port = $obj->imap_port;
				$this->imap_security = $obj->imap_security;
				$this->imap_login = $obj->imap_login;
				$this->imap_password = $obj->imap_password;
				$this->smtp_server = $obj->smtp_server;
				$this->smtp_port = $obj->smtp_port;
				$this->smtp_security = $obj->smtp_security;
				$this->smtp_login = $obj->smtp_login;
				$this->smtp_password = $obj->smtp_password;
				$this->allow_self_signed = $obj->allow_self_signed;
				$this->signature = $obj->signature;
				$this->fk_user = $obj->fk_user;
				$this->sync_limit_nb = $obj->sync_limit_nb;
				$this->sync_limit_days = $obj->sync_limit_days;
				$this->shared = $obj->shared;
				$this->status = $obj->status;
				return 1;
			}
			return 0;
		} else {
			$this->error = "Error ".$this->db->lasterror();
			return -1;
		}
	}
}
