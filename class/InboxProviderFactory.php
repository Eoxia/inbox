<?php
/**
 * \file    class/InboxProviderFactory.php
 * \ingroup inbox
 * \brief   Instantiate the right InboxProviderInterface for a given account.
 *
 * Usage:
 *   $provider = InboxProviderFactory::create($account);
 *   $provider->connect($account, $folder);
 *
 * To add a new provider:
 *   1. Create class/MyProvider.php implementing InboxProviderInterface
 *   2. Add a case below matching the provider_type value stored in llx_inbox_account
 */

require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/InboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/ImapProvider.php';

class InboxProviderFactory
{
	/**
	 * Return the provider instance matching $account->provider_type.
	 *
	 * @param  InboxAccount $account
	 * @return InboxProviderInterface
	 */
	public static function create(InboxAccount $account)
	{
		switch ($account->provider_type) {
			case 'whatsapp':
				require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/WhatsAppProvider.php';
				return new WhatsAppProvider();

			// Future providers:
			// case 'graph':
			//     require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/GraphProvider.php';
			//     return new GraphProvider();
			// case 'gmail':
			//     require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/GmailProvider.php';
			//     return new GmailProvider();
			// case 'sms':
			//     require_once DOL_DOCUMENT_ROOT.'/custom/inbox/class/SmsProvider.php';
			//     return new SmsProvider();

			case 'imap':
			default:
				return new ImapProvider();
		}
	}
}
