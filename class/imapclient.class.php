<?php
/**
 *	\file       class/imapclient.class.php
 *	\ingroup    inbox
 *	\brief      Class to connect to IMAP
 */

class ImapClient
{
	private $server;
	private $port;
	private $user;
	private $password;
	private $connection;

	public function __construct($server, $port, $user, $password)
	{
		$this->server = $server;
		$this->port = $port;
		$this->user = $user;
		$this->password = $password;
	}

	public function connect()
	{
		// Native PHP IMAP connection stub
		// $this->connection = imap_open("{".$this->server.":".$this->port."/imap/ssl}INBOX", $this->user, $this->password);
		return true;
	}

	public function fetchEmails()
	{
		// Stub to fetch emails
		return array();
	}
}
