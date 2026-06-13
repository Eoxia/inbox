<?php
/**
 *	\file       class/inboxaccount.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage Inbox Accounts
 */

require_once DOL_DOCUMENT_ROOT .'/core/class/commonobject.class.php';

class InboxAccount extends CommonObject
{
	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'inboxaccount';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'inbox_account';

	public $rowid;
	public $label;
	public $email;
	public $imap_server;
	public $imap_port;
	public $imap_security;
	public $imap_login;
	public $imap_password;
	public $smtp_server;
	public $smtp_port;
	public $smtp_security;
	public $smtp_login;
	public $smtp_password;
	public $allow_self_signed;
	public $signature;
	public $fk_user;
	public $sync_limit_nb = 500;
	public $sync_limit_days = 180;
	public $shared;
	public $status;
	public $date_creation;
	public $tms;
	
	/**
	 *  Constructor
	 *
	 *  @param      DoliDb		$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Create object into database
	 *
	 *  @param      User	$user        User that creates
	 *  @param      int		$notrigger   0=launch triggers after, 1=disable triggers
	 *  @return     int      		   	 <0 if KO, Id of created object if OK
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
	 *  Load object in memory from the database
	 *
	 *  @param      int		$id    Id object
	 *  @return     int          <0 if KO, 0 if not found, >0 if OK
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
