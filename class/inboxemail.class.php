<?php
/**
 *	\file       class/inboxemail.class.php
 *	\ingroup    inbox
 *	\brief      Class to manage Inbox Emails
 */

require_once DOL_DOCUMENT_ROOT .'/core/class/commonobject.class.php';

class InboxEmail extends CommonObject
{
	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'inboxemail';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'inbox_email';

	public $rowid;
	public $fk_inbox_account;
	public $imap_uid;
	public $message_id;
	public $subject;
	public $sender;
	public $receiver;
	public $date_email;
	public $status;
	public $body_text;
	public $body_html;
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
}
