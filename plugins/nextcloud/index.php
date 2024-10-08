<?php

class NextcloudPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		VERSION = '2.38.0',
		RELEASE  = '2024-09-16',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			\SnappyMail\Log::debug('Nextcloud', 'integrated');
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('filter.language', 'FilterLanguage');

			$this->addCss('style.css');

			$this->addJs('js/webdav.js');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');

			$this->addJs('js/composer.js');
			$this->addJsonHook('NextcloudAttachFile', 'NextcloudAttachFile');

			$this->addJs('js/messagelist.js');

			$this->addTemplate('templates/PopupsNextcloudFiles.html');
			$this->addTemplate('templates/PopupsNextcloudCalendars.html');
			$this->addTemplate('templates/PopupsNextcloudInvites.html');

//			$this->addHook('login.credentials.step-2', 'loginCredentials2');
//			$this->addHook('login.credentials', 'loginCredentials');
			$this->addHook('imap.before-login', 'beforeLogin');
			$this->addHook('smtp.before-login', 'beforeLogin');
			$this->addHook('sieve.before-login', 'beforeLogin');
		} else {
			\SnappyMail\Log::debug('Nextcloud', 'NOT integrated');
			// \OC::$server->getConfig()->getAppValue('snappymail', 'snappymail-no-embed');
			$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
		}
	}

	public function ContentSecurityPolicy(\SnappyMail\HTTP\CSP $CSP)
	{
		if (\method_exists($CSP, 'add')) {
			$CSP->add('frame-ancestors', "'self'");
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OC::$server->getUserSession()->isLoggedIn();
	}

	public function loginCredentials(string &$sEmail, string &$sLogin, ?string &$sPassword = null) : void
	{
		/**
		 * This has an issue.
		 * When user changes email address, all settings are gone as the new
		 * _data_/_default_/storage/{domain}/{local-part} is used
		 */
//		$ocUser = \OC::$server->getUserSession()->getUser();
//		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function loginCredentials2(string &$sEmail, ?string &$sPassword = null) : void
	{
		$ocUser = \OC::$server->getUserSession()->getUser();
		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function beforeLogin(\RainLoop\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		// Only login with OIDC access token if
		// it is enabled in config, the user is currently logged in with OIDC,
		// the current snappymail account is the OIDC account and no account defined explicitly
		if ($oAccount instanceof \RainLoop\Model\MainAccount
		 && \OCA\SnappyMail\Util\SnappyMailHelper::isOIDCLogin()
//		 && $oClient->supportsAuthType('OAUTHBEARER') // v2.28
		 && \str_starts_with($oSettings->passphrase, 'oidc_login|')
		) {
//			$oSettings->passphrase = \OC::$server->getSession()->get('snappymail-passphrase');
			$oSettings->passphrase = \OC::$server->getSession()->get('oidc_access_token');
			\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER');
		}
	}

	/*
	\OC::$server->getCalendarManager();
	\OC::$server->getLDAPProvider();
	*/

	public function NextcloudAttachFile() : array
	{
		$aResult = [
			'success' => false,
			'tempName' => ''
		];
		$sFile = $this->jsonParam('file', '');
		$oFiles = \OCP\Files::getStorage('files');
		if ($oFiles && $oFiles->is_file($sFile) && $fp = $oFiles->fopen($sFile, 'rb')) {
			$oActions = \RainLoop\Api::Actions();
			$oAccount = $oActions->getAccountFromToken();
			if ($oAccount) {
				$sSavedName = 'nextcloud-file-' . \sha1($sFile . \microtime());
				if (!$oActions->FilesProvider()->PutFile($oAccount, $sSavedName, $fp)) {
					$aResult['error'] = 'failed';
				} else {
					$aResult['tempName'] = $sSavedName;
					$aResult['success'] = true;
				}
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function NextcloudSaveMsg() : array
	{
		$sSaveFolder = \ltrim($this->jsonParam('folder', ''), '/');
//		$aValues = \RainLoop\Api::Actions()->decodeRawKey($this->jsonParam('msgHash', ''));
		$msgHash = $this->jsonParam('msgHash', '');
		$aValues = \json_decode(\MailSo\Base\Utils::UrlSafeBase64Decode($msgHash), true);
		$aResult = [
			'folder' => '',
			'filename' => '',
			'success' => false
		];
		if ($sSaveFolder && !empty($aValues['folder']) && !empty($aValues['uid'])) {
			$oActions = \RainLoop\Api::Actions();
			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
			}

			$sSaveFolder = $sSaveFolder ?: 'Emails';
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles) {
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
			}
			$aResult['folder'] = $sSaveFolder;
			$aResult['filename'] = \MailSo\Base\Utils::SecureFileName(
				\mb_substr($this->jsonParam('filename', '') ?: \date('YmdHis'), 0, 100)
			) . '.' . \md5($msgHash) . '.eml';


			$oMailClient->MessageMimeStream(
				function ($rResource) use ($oFiles, $aResult) {
					if (\is_resource($rResource)) {
						$aResult['success'] = $oFiles->file_put_contents("{$aResult['folder']}/{$aResult['filename']}", $rResource);
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function DoAttachmentsActions(\SnappyMail\AttachmentsAction $data)
	{
		if (static::isLoggedIn() && 'nextcloud' === $data->action) {
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles && \method_exists($oFiles, 'file_put_contents')) {
				$sSaveFolder = \ltrim($this->jsonParam('NcFolder', ''), '/');
				$sSaveFolder = $sSaveFolder ?: 'Attachments';
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = empty($aItem['fileName']) ? 'file.dat' : $aItem['fileName'];
					if (!empty($aItem['data'])) {
						$sSavedFileNameFull = static::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, $oFiles);
						if (!$oFiles->file_put_contents($sSavedFileNameFull, $aItem['data'])) {
							$data->result = false;
						}
					} else if (!empty($aItem['fileHash'])) {
						$fFile = $data->filesProvider->GetFile($data->account, $aItem['fileHash'], 'rb');
						if (\is_resource($fFile)) {
							$sSavedFileNameFull = static::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, $oFiles);
							if (!$oFiles->file_put_contents($sSavedFileNameFull, $fFile)) {
								$data->result = false;
							}
							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$ocUser = \OC::$server->getUserSession()->getUser();
			$sUID = $ocUser->getUID();
			$oUrlGen = \OC::$server->getURLGenerator();
			$sWebDAV = $oUrlGen->getAbsoluteURL($oUrlGen->linkTo('', 'remote.php') . '/dav');
//			$sWebDAV = \OCP\Util::linkToRemote('dav');
			$aResult['Nextcloud'] = [
				'UID' => $sUID,
				'WebDAV' => $sWebDAV,
				'CalDAV' => $this->Config()->Get('plugin', 'calendar', false)
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			];
			if (empty($aResult['Auth'])) {
				$config = \OC::$server->getConfig();
				$sEmail = '';
				// Only store the user's password in the current session if they have
				// enabled auto-login using Nextcloud username or email address.
				if ($config->getAppValue('snappymail', 'snappymail-autologin', false)) {
					$sEmail = $sUID;
				} else if ($config->getAppValue('snappymail', 'snappymail-autologin-with-email', false)) {
					$sEmail = $config->getUserValue($sUID, 'settings', 'email', '');
				} else {
					\SnappyMail\Log::debug('Nextcloud', 'snappymail-autologin is off');
				}
				// If the user has set credentials for SnappyMail in their personal
				// settings, override everything before and use those instead.
				$sCustomEmail = $config->getUserValue($sUID, 'snappymail', 'snappymail-email', '');
				if ($sCustomEmail) {
					$sEmail = $sCustomEmail;
				}
				if (!$sEmail) {
					$sEmail = $ocUser->getEMailAddress();
//						?: $ocUser->getPrimaryEMailAddress();
				}
/*
				if ($config->getAppValue('snappymail', 'snappymail-autologin-oidc', false)) {
					if (\OC::$server->getSession()->get('is_oidc')) {
						$sEmail = "{$sUID}@nextcloud";
						$aResult['DevPassword'] = \OC::$server->getSession()->get('oidc_access_token');
					} else {
						\SnappyMail\Log::debug('Nextcloud', 'Not an OIDC login');
					}
				} else {
					\SnappyMail\Log::debug('Nextcloud', 'OIDC is off');
				}
*/
				$aResult['DevEmail'] = $sEmail ?: '';
			} else if (!empty($aResult['ContactsSync'])) {
				$bSave = false;
				if (empty($aResult['ContactsSync']['Url'])) {
					$aResult['ContactsSync']['Url'] = "{$sWebDAV}/addressbooks/users/{$sUID}/contacts/";
					$bSave = true;
				}
				if (empty($aResult['ContactsSync']['User'])) {
					$aResult['ContactsSync']['User'] = $sUID;
					$bSave = true;
				}
				$pass = \OC::$server->getSession()['snappymail-passphrase'];
				if ($pass/* && empty($aResult['ContactsSync']['Password'])*/) {
					$pass = \SnappyMail\Crypt::DecryptUrlSafe($pass, $sUID);
					if ($pass) {
						$aResult['ContactsSync']['Password'] = $pass;
						$bSave = true;
					}
				}
				if ($bSave) {
					$oActions = \RainLoop\Api::Actions();
					$oActions->setContactsSyncData(
						$oActions->getAccountFromToken(),
						array(
							'Mode' => $aResult['ContactsSync']['Mode'],
							'User' => $aResult['ContactsSync']['User'],
							'Password' => $aResult['ContactsSync']['Password'],
							'Url' => $aResult['ContactsSync']['Url']
						)
					);
				}
			}
		}
	}

	public function FilterLanguage(&$sLanguage, $bAdmin) : void
	{
		if (!\RainLoop\Api::Config()->Get('webmail', 'allow_languages_on_settings', true)) {
			$aResultLang = \SnappyMail\L10n::getLanguages($bAdmin);
			$userId = \OC::$server->getUserSession()->getUser()->getUID();
			$userLang = \OC::$server->getConfig()->getUserValue($userId, 'core', 'lang', 'en');
			$userLang = \strtr($userLang, '_', '-');
			$sLanguage = $this->determineLocale($userLang, $aResultLang);
			// Check if $sLanguage is null
			if (!$sLanguage) {
				$sLanguage = 'en'; // Assign 'en' if $sLanguage is null
			}
		}
	}

	/**
	 * Determine locale from user language.
	 *
	 * @param string $langCode The name of the input.
	 * @param array  $languagesArray The value of the array.
	 *
	 * @return string return locale
	 */
	private function determineLocale(string $langCode, array $languagesArray) : ?string
	{
		// Direct check for the language code
		if (\in_array($langCode, $languagesArray)) {
			return $langCode;
		}

		// Check without country code
		if (\str_contains($langCode, '-')) {
			$langCode = \explode('-', $langCode)[0];
			if (\in_array($langCode, $languagesArray)) {
				return $langCode;
			}
		}

		// Check with uppercase country code
		$langCodeWithUpperCase = $langCode . '-' . \strtoupper($langCode);
		if (\in_array($langCodeWithUpperCase, $languagesArray)) {
			return $langCodeWithUpperCase;
		}

		// If no match is found
		return null;
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('suggestions' === $sName && $this->Config()->Get('plugin', 'suggestions', true)) {
				if (!\is_array($mResult)) {
					$mResult = array();
				}
				include_once __DIR__ . '/NextcloudContactsSuggestions.php';
				$mResult[] = new NextcloudContactsSuggestions(
					$this->Config()->Get('plugin', 'ignoreSystemAddressbook', true)
				);
			}
/*
			if ($this->Config()->Get('plugin', 'storage', false) && ('storage' === $sName || 'storage-local' === $sName)) {
				require_once __DIR__ . '/storage.php';
				$oDriver = new \NextcloudStorage(APP_PRIVATE_DATA.'storage', $sName === 'storage-local');
			}
*/
		}
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('suggestions')->SetLabel('Suggestions')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
			\RainLoop\Plugins\Property::NewInstance('ignoreSystemAddressbook')->SetLabel('Ignore system addressbook')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
/*
			\RainLoop\Plugins\Property::NewInstance('storage')->SetLabel('Use Nextcloud user ID in config storage path')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
*/
			\RainLoop\Plugins\Property::NewInstance('calendar')->SetLabel('Enable "Put ICS in calendar"')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
		);
	}

	private static function SmartFileExists(string $sFilePath, $oFiles) : string
	{
		$sFilePath = \str_replace('\\', '/', \trim($sFilePath));

		if (!$oFiles->file_exists($sFilePath)) {
			return $sFilePath;
		}

		$aFileInfo = \pathinfo($sFilePath);

		$iIndex = 0;

		while (true) {
			++$iIndex;
			$sFilePathNew = $aFileInfo['dirname'].'/'.
				\preg_replace('/\(\d{1,2}\)$/', '', $aFileInfo['filename']).
				' ('.$iIndex.')'.
				(empty($aFileInfo['extension']) ? '' : '.'.$aFileInfo['extension'])
			;
			if (!$oFiles->file_exists($sFilePathNew)) {
				return $sFilePathNew;
			}
			if (10 < $iIndex) {
				break;
			}
		}
		return $sFilePath;
	}
}
