<?php
/**
 *	\file       core/modules/modInbox.class.php
 *	\ingroup    inbox
 *	\brief      Description and activation file for the module Inbox
 */

include_once DOL_DOCUMENT_ROOT .'/core/modules/DolibarrModules.class.php';

class modInbox extends DolibarrModules
{
	/**
	 *   Constructor. Define names, constants, directories, boxes, permissions
	 *
	 *   @param      DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Id for module (must be unique).
		$this->numero = 104500;
		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'inbox';

		// Family can be 'crm','financial','hr','projects','products','ecm','technic','other'
		$this->family = 'crm';
		// Module label (no space allowed), used if translation string 'ModuleXXXName' not found (where XXX is value of numeric property 'numero' of module)
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		// Module description, used if translation string 'ModuleXXXDesc' not found (where XXX is value of numeric property 'numero' of module)
		$this->description = "Webmail natif collaboratif pour Dolibarr.";
		// Possible values for version are: 'development', 'experimental', 'dolibarr' or version
		$this->version = '1.0.0';
		// Key used in llx_const table to save module status enabled/disabled (where MYMODULE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		// Name of image file used for this module.
		$this->picto = 'object_email';

		// Data directories to create when module is enabled
		$this->dirs = array(
			"/inbox/temp"
		);

		// Config pages.
		$this->config_page_url = array("setup.php@inbox");

		// Dependencies
		$this->depends = array();
		$this->requiredby = array();

		// Constants
		$this->const = array();

		// Main menu entries
		$this->menu = array(
			array(
				'fk_menu' => '0', // 0 if top menu
				'type' => 'top',
				'titre' => 'Inbox',
				'mainmenu' => 'inbox',
				'url' => '/inbox/index.php',
				'langs' => 'inbox@inbox',
				'position' => 100,
				'enabled' => '$conf->inbox->enabled',
				'perms' => '$user->rights->inbox->read',
				'target' => '',
				'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
				'picto' => 'object_email',
			)
		);

		// Permissions
		$this->rights = array();
		$this->rights_class = 'inbox';
		$r = 0;

		$r++;
		$this->rights[$r][0] = 104501;
		$this->rights[$r][1] = 'Read emails';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = 104502;
		$this->rights[$r][1] = 'Create/modify emails and accounts';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$r++;
		$this->rights[$r][0] = 104503;
		$this->rights[$r][1] = 'Delete emails';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'delete';

		$r++;
		$this->rights[$r][0] = 104504;
		$this->rights[$r][1] = 'Setup module';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'setup';
	}

	/**
	 *  Function called when module is enabled.
	 *  Load tables, menus, etc.
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/inbox/sql/');
		if ($result < 0) {
			// Do not activate module if error 'not allowed' returned when loading module SQL queries
			// (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
			return -1;
		}
		$sql = array();
		return $this->_init($sql, $options);
	}
}
