<?php

/**
 * AD-X
 *
 * Licensed under the BSD (3-Clause) license
 * For full copyright and license information, please see the LICENSE file
 *
 * @copyright		2012-2013 Robert Rossmann
 * @author			Robert Rossmann <rr.rossmann@me.com>
 * @link			https://github.com/Alaneor/AD-X
 * @license			http://choosealicense.com/licenses/bsd-3-clause		BSD (3-Clause) License
 */


namespace ADX\Classes;

use ADX\Core;
use ADX\Enums;
use ADX\Core\Object;
use ADX\Util\Exchange\AddressListSelector;
use ADX\Util\Exchange\MailboxStoreSelector;
use ADX\Util\Exchange\TransferAgentSelector;
use Ramsey\Uuid\Uuid;

/**
 * Class representing a 'user' objectClass in Active Directory
 *
 * This class provides user-class-specific methods to simplify
 * user creation and manipulation in Active Directory. It also provides some MS Exchange-related
 * functionality like managing mailboxes and mail users.
 */
class User extends Object
{
	/**
	 * Use this attribute set when loading data from directory to satisfy all attribute
	 * requirements and recommendations of the methods provided by this class
	 *
	 * @var		array
	 */
	public static $defaultAttributeSet = [
		'userAccountControl',
		'userAccountControlComputed',
		'pwdLastSet',
		'lockoutTime',
		'msExchMailboxGuid',
		'targetAddress',
		'proxyAddresses',
	];


	/**
	 * Create a new instance of the user
	 *
	 * In order to successfully create a new user account you **must** provide the following
	 * attributes the moment you instantiate a new object:
	 *
	 * - `cn` - the user's cn forms part of the distinguished name and cannot be omitted
	 * - `samAccountName` - the username used to log in
	 *
	 * <br>
	 * The following attributes are calculated automatically for you:
	 * - `name`, `displayName` - equals to `cn`
	 * - `userPrincipalName` - equals to `samAccountName` + *@* + *domain name* ( *domain name* is generated from rootDSE's defaultNamingContext )
	 * - `userAccountControl` - if you provide user's password at this moment, it will include only
	 *   the {@link ADX\Enums\UAC::NormalAccount} bit; if no password is present, it will also include
	 *   {@link ADX\Enums\UAC::PasswdNotReqd} and {@link ADX\Enums\UAC::AccountDisable} bits. This is default behaviour of Active Directory.
	 *
	 * <br>
	 * <p class='alert'>If you set the user's password before creating the account, it will be created
	 * **without** the requirement to reset the password upon next logon. To force user to change the
	 * password next time he/she logs in, use the {@link self::force_password_change()} method
	 * before calling {@link self::create()}.</p>
	 *
	 * @param		ADX\Core\Link		A configured and bound {@link ADX\Core\Link} object
	 * @param		array				A named array containing the attribute names as indexes and their values
	 */
	public function __construct( Core\Link $link, $attributes = array() )
	{
		$args = func_get_args();
		$comesFromLdap = isset( $args[2] ) ? $args[2] : false;	// This parameter is hidden

		parent::__construct( $link, $attributes, $comesFromLdap );

		// Only perform steps for new object creation when the object has not been
		// returned from ldap server
		( ! $comesFromLdap ) && $this->_prepare_new_object();
	}

	/**
	 * Create the user on the server
	 *
	 * This method provides some extra functionality to the standard
	 * {@link ADX\Core\Object::create()} method.
	 *
	 * If you have set the user's password then the following updates will be
	 * made to the user account:
	 *
	 * - The {@link ADX\Enums\UAC::PasswdNotReqd} bit will be turned **off**
	 * - The user account will be created as **enabled**
	 * - The user will **not** be forced to change the password unless you have explicitly
	 *   forced a password change with {@link self::force_password_change()}
	 *
	 * <br>
	 * If, for whatever reason you need any of the above `userAccountControl` bits to be different
	 * you must override the values **after** the user has been created on the server,
	 * meaning, after you have called the `create()` method on the object.
	 *
	 * @param		string		The distinguished name of the parent container where this user should be stored
	 *
	 * @return		self
	 */
	public function create( $dn )
	{
		// Check if password has been set since initialisation and modify UserAccountControl
		// to enable the account but also to require password for logon
		if ( in_array( 'unicodepwd', $this->all_attributes() ) )
		{
			$this->bit_state( 'userAccountControl', Enums\UAC::PasswdNotReqd, false );
			$this->bit_state( 'userAccountControl', Enums\UAC::AccountDisable, false );
		}

		return parent::create( $dn );
	}

	/**
	 * Is the user's account locked?
	 *
	 * <p class='alert'>The attribute *lockoutTime* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function is_locked()
	{
		return (bool)$this->lockoutTime(0);
	}

	/**
	 * Is the user's account disabled?
	 *
	 * <p class='alert'>The attribute *userAccountControl* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function is_disabled()
	{
		return $this->bit_state( 'userAccountControl', Enums\UAC::AccountDisable );
	}

	/**
	 * Is the user required to update the account's password?
	 *
	 * <p class='alert'>The attribute *pwdLastSet* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function must_change_password()
	{
		return ! (bool)$this->pwdLastSet(0);
	}

	/**
	 * Is the user's password expired?
	 *
	 * <p class='alert'>The attribute *userAccountControlComputed* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function password_expired()
	{
		return $this->bit_state( 'userAccountControlComputed', Enums\UAC::PasswordExpired );
	}

	/**
	 * Unlock the user's account
	 *
	 * @return		self
	 */
	public function unlock()
	{
		$this->set( 'lockoutTime', 0 );

		return $this;
	}

	/**
	 * Enable the user's account
	 *
	 * <p class='alert'>The attribute *userAccountControl* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		self
	 */
	public function enable()
	{
		if ( ! in_array( 'useraccountcontrol', $this->all_attributes() ) ) throw new Core\InvalidOperationException( "userAccountControl attribute must be loaded from server for correct behaviour" );

		$this->bit_state( 'userAccountControl', Enums\UAC::AccountDisable, false );

		return $this;
	}

	/**
	 * Disable the user's account
	 *
	 * <p class='alert'>The attribute *userAccountControl* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		self
	 */
	public function disable()
	{
		if ( ! in_array( 'useraccountcontrol', $this->all_attributes() ) ) throw new Core\InvalidOperationException( "userAccountControl attribute must be loaded from server for correct behaviour" );

		$this->bit_state( 'userAccountControl', Enums\UAC::AccountDisable, true );

		return $this;
	}

	/**
	 * Set the user's password to a new value
	 *
	 * <p class='alert'>Note that Active Directory will not allow you to reset users'
	 * passwords over unencrypted connection - you must connect with SSL or TLS encryption
	 * in order for this method to work.</p>
	 *
	 * @param		string		The new password the user's account should receive
	 *
	 * @return		self
	 */
	public function set_password( $newPassword )
	{
		$this->set( 'unicodePwd', $newPassword );

		return $this;
	}

	/**
	 * Require the user to change password on next logon, or turn this requirement off
	 *
	 * @param		bool		If set to false, this requirement will be turned off
	 *
	 * @return		self
	 */
	public function force_password_change( $enable = true )
	{
		$this->set( 'pwdLastSet', $enable ? 0 : -1 );

		return $this;
	}

	/**
	 * Does this user have an Exchange mailbox?
	 *
	 * <p class='alert'>The attribute *msExchMailboxGuid* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function has_mailbox()
	{
		return (bool)$this->msExchMailboxGuid();
	}

	/**
	 * Is the current user a mail user?
	 *
	 * <p class='alert'>The attribute *targetAddress* must be loaded from the server
	 * in order for this method to function properly.</p>
	 *
	 * @return		bool
	 */
	public function is_mailuser()
	{
		return (bool)$this->targetAddress();
	}

	/**
	 * Create an Exchange mailbox for this user
	 *
	 * <p class='alert'>The attribute *proxyAddresses* should be loaded from the server
	 * to avoid unintended data loss.</p>
	 *
	 * @uses		self::has_mailbox()
	 * @uses		self::is_mailuser()
	 *
	 * @param		string		The email address to be used with this mailbox
	 * @param		mixed		@todo
	 * @param		mixed		The address book the mail user should appear in. You can either omit this
	 *							value ( then default will be used ), pass an instance of Object or a distinguished name
	 *							of an address book or provide an AddressListSelector from which the default value will be used
	 *							( this is primarily useful for performance reasons as omitting the value will cause that the default
	 *							Address Book be loaded from server for each method call )
	 *
	 * @return		self
	 */
	public function new_mailbox( $replyAddress, $mailboxDB = null, $showInAddressBook = null )
	{
		// First check if we are not trying to mail-enable an already mail-enabled user
		if ( $this->is_mailuser() || $this->has_mailbox() ) throw new Core\InvalidOperationException( "This user is already mail-enabled" );

		// Tabula rasa ( clean slate )
		$this->_remove_exchange_properties();

		// Prepare dynamically calculated values
		$store	= $this->_pick_mailbox_store( $mailboxDB );
		$book	= $this->_pick_address_book( $showInAddressBook );
		$server	= $store->resolve( 'msExchOwningServer', 'legacyExchangeDn' )->first();
		$mta	= ( new TransferAgentSelector( $this->adxLink ) )->for_server( $server )->first();

		// Set all attributes critical for a mailbox-enabled user
		$this->mail							->set( $replyAddress );
		$this->mailNickname					->set( explode( '@', $replyAddress )[0] );
		$this->proxyAddresses				->add( "SMTP:" . $replyAddress );
		$this->showInAddressBook			->set( $book );
		$this->msExchRecipientDisplayType	->set( Enums\RecipientDisplayType::ACLableMailboxUser );
		$this->msExchRecipientTypeDetails	->set( Enums\RecipientTypeDetails::UserMailbox );
		$this->msExchPoliciesIncluded		->set( Enums\ExchangeCommon::DefaultPolicyGUID );
		$this->legacyExchangeDn				->set( Enums\ExchangeCommon::AdminGroupLDN . '/cn=Recipients/cn=' . $this->mailNickname(0) );
		$this->msExchMailboxGuid			->set( (string)Uuid::uuid4() );
		$this->HomeMDB						->set( $store->dn );
		$this->HomeMTA						->set( $mta->dn );
		$this->msExchVersion				->set( $store->msExchVersion(0) );
		$this->msExchHomeServerName			->set( $server->legacyExchangeDn(0) );
		$this->mDBUseDefaults				->set( true );

		return $this;
	}

	/**
	 * Create an Exchange mailUser for this user
	 *
	 * <p class='alert'>The attribute *proxyAddresses* should be loaded from the server
	 * to avoid unintended data loss.</p>
	 *
	 * @uses		self::is_mailuser()
	 * @uses		self::has_mailbox()
	 *
	 * @param		string		The external email address to be associated with this mail user
	 * @param		string		An optional reply address - this will become the primary SMTP address for the user
	 *							and the external address will be only used for forwarding
	 * @param		mixed		The address book the mail user should appear in. You can either omit this
	 *							value ( then default will be used ), pass an instance of Object or a distinguished name
	 *							of an address book or provide an AddressListSelector from which the default value will be used
	 *							( this is primarily useful for performance reasons as omitting the value will cause that the default
	 *							Address Book be loaded from server for each method call )
	 *
	 * @return		self
	 */
	public function mail_enable( $externalAddress, $replyAddress = null, $showInAddressBook = null )
	{
		// First check if we are not trying to mail-enable an already mail-enabled user
		if ( $this->is_mailuser() || $this->has_mailbox() ) throw new Core\InvalidOperationException( "This user is already mail-enabled" );

		// Tabula rasa ( clean slate )
		$this->_remove_exchange_properties();

		// Make sure that externalAddress and replyAddress are different
		if ( $externalAddress === $replyAddress ) unset( $replyAddress );

		// Set the correct mail address
		$this->mail->set( $replyAddress ?: $externalAddress );

		// Set the mailNickname and proxyAddresses
		if ( $replyAddress )
		{
			$this->mailNickname->set( explode( '@', $replyAddress )[0] );
			$replyAddress		= "SMTP:" . $replyAddress;
			$externalAddress	= "smtp:" . $externalAddress;
			$this->proxyAddresses->add( [$replyAddress, $externalAddress] );
		}
		else
		{
			$this->mailNickname->set( explode( '@', $externalAddress )[0] );
			$externalAddress = "SMTP:" . $externalAddress;
			$this->proxyAddresses->add( $externalAddress );
		}

		// Set or compute other critical attributes
		$this->msExchRecipientDisplayType	->set( Enums\RecipientDisplayType::MailUser );
		$this->targetAddress				->set( $externalAddress );
		$this->showInAddressBook			->set( $this->_pick_address_book( $showInAddressBook ) );
		$this->msExchPoliciesIncluded		->set( Enums\ExchangeCommon::DefaultPolicyGUID );
		$this->legacyExchangeDn				->set( Enums\ExchangeCommon::AdminGroupLDN . '/cn=Recipients/cn=' . $this->mailNickname(0) );

		return $this;
	}

	/**
	 * Disable the user's Exchange functionality
	 *
	 * <p class='alert'>The attribute *proxyAddresses* should be loaded from the server
	 * to avoid unintended data loss.</p>
	 *
	 * @return		self
	 */
	public function mail_disable()
	{
		$this->_remove_exchange_properties();

		// Remove all Exchange related addresses from proxyAddresses list
		// ( all x400, x500 and smtp addresses )
		$addresses = $this->get( 'proxyAddresses' )->value();
		$addresses = $this->_remove_address_types( ['smtp:', 'x400:', 'x500:'], $addresses );

		$this->proxyAddresses->set( $addresses );

		return $this;
	}


	/**
	 * Prepare a completely new user object for storage on ldap
	 *
	 * @return		void
	 */
	protected function _prepare_new_object()
	{
		$mandatory = ['cn', 'samaccountname'];
		$diff = array_diff( $mandatory, array_change_key_case( $this->all_attributes() ) ); // Do we have all required params present?

		if ( count( $diff ) > 0 ) throw new Core\IncorrectParameterException( "Mandatory attribute '" . implode( "', '", $diff ) . "' is missing" );

		// Generate UserPrincipalName
		$domain = ldap_explode_dn( $this->adxLink->rootDSE->defaultNamingContext(0), 1 );
		unset( $domain['count'] );
		$domain = implode( '.', $domain );

		$this->UserPrincipalName->set( $this->samaccountname(0) . '@' . $domain );
		// Name and displayName should be equivalent to CN by default
		$this->name->set( $this->cn(0) );
		$this->displayName->set( $this->cn(0) );

		// Set the UserAccountControl attribute to its defaults after account creation
		$this->userAccountControl->clear();
		$this->bit_state( 'userAccountControl', Enums\UAC::NormalAccount, true );	// Default, must be set for all users

		if ( ! in_array( 'unicodepwd', $this->all_attributes() ) )
		{
			// We don't know yet if password will be provided so let's go with strict security.
			// Since we MUST set PasswdNotReqd if password has not been set, let's also
			// disable the account to prevent passwordless logon
			$this->bit_state( 'userAccountControl', Enums\UAC::PasswdNotReqd, true );
			$this->bit_state( 'userAccountControl', Enums\UAC::AccountDisable, true );
		}
	}

	/**
	 * Remove all Exchange properties from the user
	 *
	 * @return		self
	 */
	protected function _remove_exchange_properties()
	{
		// Remove all Exchange properties
		// Purposefully NOT removing the mail attribute as this is NOT an Exchange attribute
		$this->HomeMDB								->clear();
		$this->HomeMTA								->clear();
		$this->internetEncoding						->clear();
		$this->mDBUseDefaults						->clear();
		$this->msExchMailboxAuditEnable				->clear();
		$this->msExchAddressBookFlags				->clear();
		$this->msExchArchiveQuota					->clear();
		$this->msExchArchiveWarnQuota				->clear();
		$this->msExchBypassAudit					->clear();
		$this->msExchDumpsterQuota					->clear();
		$this->msExchDumpsterWarningQuota			->clear();
		$this->msExchHomeServerName					->clear();
		$this->msExchMailboxAuditEnable				->clear();
		$this->msExchMailboxAuditLogAgeLimit		->clear();
		$this->msExchMailboxGuid					->clear();
		$this->msExchMailboxSecurityDescriptor		->clear();
		$this->msExchMDBRulesQuota					->clear();
		$this->msExchModerationFlags				->clear();
		$this->msExchPoliciesExcluded				->clear();
		$this->msExchPoliciesIncluded				->clear();
		$this->msExchProvisioningFlags				->clear();
		$this->msExchRBACPolicyLink					->clear();
		$this->msExchRecipientDisplayType			->clear();
		$this->msExchRecipientTypeDetails			->clear();
		$this->msExchTransportRecipientSettingsFlags->clear();
		$this->msExchUMEnabledFlags					->clear();
		$this->msExchUMPinChecksum					->clear();
		$this->msExchUMRecipientDialPlanLink		->clear();
		$this->msExchUMTemplateLink					->clear();
		$this->msExchUserAccountControl				->clear();
		$this->msExchUserCulture					->clear();
		$this->msExchVersion						->clear();

		$this->targetAddress						->clear();
		$this->mailNickname							->clear();
		$this->showInAddressBook					->clear();
		$this->textEncodedORAddress					->clear();
		$this->legacyExchangeDn						->clear();

		return $this;
	}

	/**
	 * Remove all specified address types from the given list of addresses ( usually loaded from proxyAddresses )
	 *
	 * @param		array		The address types to be removed
	 * @param		array		The list of addresses from which to remove the address types ( typically a proxyAddress ldap attribute )
	 *
	 * @return		array		The filtered list of addresses
	 */
	protected function _remove_address_types( $types = array(), $from = array() )
	{
		foreach ( $from as $index => $address )
		{
			$address = strtolower( $address );

			foreach ( $types as $type )
			{
				if ( stripos( $address, $type ) !== false ) unset( $from[$index] );
			}
		}

		return array_values( $from );
	}

	/**
	 * Pick an address book from the given input
	 *
	 * @param		Object|Selector|string		The input from which the address book will be chosen or null for default address book
	 *
	 * @return		string						The distinguished name of the address book
	 */
	protected function _pick_address_book( $fromInput = null )
	{
		if ( is_string( $fromInput ) )						return $fromInput;
		if ( $fromInput instanceof Object )					return $fromInput->dn;
		if ( $fromInput instanceof AddressListSelector )	return $fromInput->defaultGAL()->dn;
		if ( $fromInput === null )							return ( new AddressListSelector( $this->adxLink ) )->defaultGAL()->dn;

		// Still running here? Then something's wrong...
		throw new InvalidParameterException( 'Could not convert input into valid Address List DN' );
	}

	/**
	 * Pick an Exchange mailbox store from the given input
	 *
	 * You must not use a string or a distinguished name as an input to this function.
	 *
	 * @param		mixed		The input from which the mailbox store will be chosen or null for a random mailbox store
	 *
	 * @return		Object		The Object representing the mailbox store
	 */
	protected function _pick_mailbox_store( $fromInput = null )
	{
		if ( $fromInput instanceof Object )					return $fromInput;
		if ( $fromInput instanceof MailboxStoreSelector )	return $fromInput->pick();
		if ( $fromInput === null )							return ( new MailboxStoreSelector( $this->adxLink ) )->pick();

		// Still running here? Then something's wrong...
		throw new InvalidParameterException( 'Could not convert input into valid Exchange mailbox store' );
	}
}
