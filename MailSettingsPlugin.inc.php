<?php

/**
 * @file plugins/generic/mailSettings/MailSettingsPlugin.inc.php
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MailSettingsPlugin
 * @ingroup plugins_generic_mailSettings
 *
 * @brief Mail Settings plugin - manage SMTP/mail settings from the OJS admin panel
 *        without editing config.inc.php
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class MailSettingsPlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success && $this->getEnabled()) {
			// Hook into mail send to override SMTP settings
			HookRegistry::register('Mail::send', array($this, 'overrideMailSettings'));

			// Register page handler for standalone settings page
			HookRegistry::register('LoadHandler', array($this, 'loadPageHandler'));

			// Add sidebar link for admin/manager
			HookRegistry::register('TemplateManager::display', array($this, 'addSidebarLink'));
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.mailSettings.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.mailSettings.description');
	}

	// =========================================================================
	// PAGE HANDLER ROUTING
	// =========================================================================

	/**
	 * Register the page handler for /mailSettings URL
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 */
	public function loadPageHandler($hookName, $args) {
		$page = $args[0];

		if ($page === 'mailSettings') {
			$this->import('MailSettingsHandler');
			define('HANDLER_CLASS', 'MailSettingsHandler');
			return true;
		}

		return false;
	}

	// =========================================================================
	// SIDEBAR LINK (Admin Panel Left Menu)
	// =========================================================================

	/**
	 * Add a link to the OJS backend sidebar for admin/manager users
	 * Uses JavaScript DOM injection (same approach as Bulk Plugin Manager)
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 */
	public function addSidebarLink($hookName, $args) {
		$templateMgr = $args[0];
		$template = $args[1];

		// Check if this is a backend page
		$backendTemplates = array(
			'management/', 'admin/', 'dashboard/', 'submissions',
			'authorDashboard', 'workflow/', 'stats/', 'statistics/',
			'tools/', 'settings/', 'users/', 'manageIssues/',
			'editorialActivity', 'reports/', 'article'
		);

		$isBackend = false;
		foreach ($backendTemplates as $tpl) {
			if (strpos($template, $tpl) !== false) {
				$isBackend = true;
				break;
			}
		}

		// Alternative: check by requested page
		if (!$isBackend) {
			$request = Application::get()->getRequest();
			$router = $request->getRouter();
			if ($router && method_exists($router, 'getRequestedPage')) {
				$page = $router->getRequestedPage($request);
				$backendPages = array(
					'management', 'manageIssues', 'stats', 'submissions',
					'workflow', 'settings', 'tools', 'admin', 'user',
					'mailSettings'
				);
				if (in_array($page, $backendPages)) {
					$isBackend = true;
				}
			}
		}

		if (!$isBackend) {
			return false;
		}

		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) {
			return false;
		}

		$contextId = $context->getId();
		$user = $request->getUser();
		if (!$this->userHasAccess($user, $contextId)) {
			return false;
		}

		$router = $request->getRouter();
		$dispatcher = $router->getDispatcher();
		$url = $dispatcher->url($request, ROUTE_PAGE, null, 'mailSettings');

		$menuLabel = __('plugins.generic.mailSettings.sidebar.label');

		$templateMgr->addJavaScript(
			'mailSettingsSidebar',
			'
			document.addEventListener("DOMContentLoaded", function() {
				var nav = document.querySelector(".pkp_nav_list, .app__nav, nav[role=navigation] ul, .pkpNav__list");
				if (!nav) {
					nav = document.querySelector("#navigationPrimary ul, .pkp_navigation_primary ul");
				}

				if (nav) {
					if (document.getElementById("mailSettingsSidebarLink")) return;

					var li = document.createElement("li");
					li.id = "mailSettingsSidebarLink";
					li.className = nav.children[0] ? nav.children[0].className : "";

					var a = document.createElement("a");
					a.href = "' . $url . '";
					a.innerHTML = "&#128231; ' . addslashes($menuLabel) . '";
					a.className = nav.querySelector("a") ? nav.querySelector("a").className : "";

					li.appendChild(a);
					nav.appendChild(li);
				}
			});
			',
			array(
				'inline' => true,
				'contexts' => array('backend')
			)
		);

		return false;
	}

	// =========================================================================
	// SETTINGS LINK (Plugin List)
	// =========================================================================

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs) {
		import('lib.pkp.classes.linkAction.request.RedirectAction');
		$actions = parent::getActions($request, $actionArgs);

		if (!$this->getEnabled()) {
			return $actions;
		}

		$user = $request->getUser();
		$contextId = ($request->getContext()) ? $request->getContext()->getId() : CONTEXT_SITE;

		if ($this->userHasAccess($user, $contextId)) {
			$dispatcher = $request->getDispatcher();
			array_unshift(
				$actions,
				new LinkAction(
					'settings',
					new RedirectAction(
						$dispatcher->url($request, ROUTE_PAGE, null, 'mailSettings')
					),
					__('manager.plugins.settings'),
					null
				)
			);
		}

		return $actions;
	}

	// =========================================================================
	// CORE HOOK: OVERRIDE MAIL SETTINGS
	// =========================================================================

	/**
	 * Hook callback: Override mail sending with custom SMTP settings
	 *
	 * When custom settings are active, this hook takes FULL CONTROL of mail
	 * sending. It creates its own PHPMailer instance, configures it with the
	 * plugin's SMTP settings, sends the email, and returns true to prevent
	 * OJS from sending again with config.inc.php settings.
	 *
	 * @param $hookName string
	 * @param $args array [&$mail, &$recipients, &$subject, &$body, &$headers, &$additionalParams]
	 * @return bool true = we handled it, false = let OJS handle it
	 */
	public function overrideMailSettings($hookName, $args) {
		$mail =& $args[0];

		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextId = ($context) ? $context->getId() : CONTEXT_SITE;

		$settings = $this->_getEffectiveSettings($contextId);

		// No custom settings active — let OJS handle normally
		if (!$settings) {
			return false;
		}

		// Load PHPMailer
		$phpMailerClass = $this->_loadPhpMailerClass();
		if (!$phpMailerClass) {
			error_log('MailSettings Plugin: PHPMailer class not found, falling back to OJS default.');
			return false;
		}

		try {
			$mailer = new $phpMailerClass(true);

			// Configure mail method
			$this->_configureMailerTransport($mailer, $settings);

			// Transfer all mail data from OJS Mail object to PHPMailer
			$this->_transferMailData($mailer, $mail, $settings);

			// Send
			$mailer->send();

			// Increment daily mail counter
			$this->_incrementMailCounter($contextId);

			// Return true = we handled it, OJS should not send again
			return true;

		} catch (Exception $e) {
			error_log('MailSettings Plugin send failed: ' . $e->getMessage());
			// Fall back to OJS default sending
			return false;
		}
	}

	/**
	 * Get the effective mail settings for a given context
	 *
	 * @param $contextId int
	 * @return array|null
	 */
	private function _getEffectiveSettings($contextId) {
		// Only journal-level settings are supported
		if (!$contextId || $contextId === CONTEXT_SITE) {
			return null;
		}

		$journalSettings = $this->loadSettings($contextId);
		if ($journalSettings && $journalSettings['configSource'] === 'custom') {
			return $journalSettings;
		}

		return null;
	}

	// =========================================================================
	// PHPMAILER LOADING & CONFIGURATION
	// =========================================================================

	/**
	 * Find and load PHPMailer class
	 *
	 * @return string|null PHPMailer class name or null
	 */
	private function _loadPhpMailerClass() {
		if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
			return 'PHPMailer\PHPMailer\PHPMailer';
		}
		if (class_exists('PHPMailer')) {
			return 'PHPMailer';
		}

		$basePath = Core::getBaseDir();
		$paths = array(
			$basePath . '/lib/pkp/lib/vendor/phpmailer/phpmailer/src/PHPMailer.php',
			$basePath . '/lib/pkp/lib/vendor/phpmailer/phpmailer/src/SMTP.php',
			$basePath . '/lib/pkp/lib/vendor/phpmailer/phpmailer/src/Exception.php',
		);
		foreach ($paths as $path) {
			if (file_exists($path)) require_once($path);
		}
		if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
			return 'PHPMailer\PHPMailer\PHPMailer';
		}

		return null;
	}

	/**
	 * Configure PHPMailer transport (SMTP, mail, sendmail) from plugin settings
	 *
	 * @param $mailer object PHPMailer instance
	 * @param $settings array Plugin settings
	 */
	private function _configureMailerTransport($mailer, $settings) {
		$mailMethod = isset($settings['mailMethod']) ? $settings['mailMethod'] : 'smtp';

		if ($mailMethod === 'smtp') {
			$mailer->isSMTP();
			$mailer->Host = isset($settings['smtpServer']) ? $settings['smtpServer'] : '';
			$mailer->Port = isset($settings['smtpPort']) ? (int)$settings['smtpPort'] : 587;
			$mailer->SMTPAuth = true;
			$mailer->Username = isset($settings['smtpUsername']) ? $settings['smtpUsername'] : '';
			$mailer->Password = isset($settings['smtpPassword']) ? $this->decryptPassword($settings['smtpPassword']) : '';

			$encryption = isset($settings['smtpEncryption']) ? $settings['smtpEncryption'] : 'tls';
			if ($encryption === 'tls') {
				$mailer->SMTPSecure = 'tls';
			} elseif ($encryption === 'ssl') {
				$mailer->SMTPSecure = 'ssl';
			} else {
				$mailer->SMTPSecure = '';
				$mailer->SMTPAutoTLS = false;
			}

			if (!empty($settings['smtpAuthType'])) {
				$mailer->AuthType = $settings['smtpAuthType'];
			}

			if (!empty($settings['suppressCertCheck'])) {
				$mailer->SMTPOptions = array(
					'ssl' => array(
						'verify_peer' => false,
						'verify_peer_name' => false,
						'allow_self_signed' => true,
					)
				);
			}
		} elseif ($mailMethod === 'sendmail') {
			$mailer->isSendmail();
		} else {
			$mailer->isMail();
		}
	}

	/**
	 * Transfer all mail data from OJS Mail object to PHPMailer instance
	 *
	 * Extracts recipients, subject, body, From, Reply-To, CC, BCC, and
	 * attachments from the OJS Mail/MailTemplate object and sets them
	 * on the PHPMailer instance.
	 *
	 * @param $mailer object PHPMailer instance
	 * @param $mail object OJS Mail object
	 * @param $settings array Plugin settings
	 */
	private function _transferMailData($mailer, $mail, $settings) {
		// Character encoding
		$mailer->CharSet = Config::getVar('i18n', 'client_charset', 'utf-8');

		// ---- FROM ----
		// SMTP servers (Gmail, Yandex, etc.) reject mail when From doesn't match
		// the authenticated account. We use the SMTP username or envelope sender
		// as From, and set the original OJS From as Reply-To so replies go to
		// the right person.
		$originalFrom = $mail->getFrom();
		$originalFromEmail = '';
		$originalFromName = '';
		if (is_array($originalFrom)) {
			$originalFromEmail = isset($originalFrom['email']) ? $originalFrom['email'] : '';
			$originalFromName = isset($originalFrom['name']) ? $originalFrom['name'] : '';
		}

		// Determine the actual From address the SMTP server will accept
		$smtpFrom = '';
		if (!empty($settings['envelopeSender'])) {
			$smtpFrom = $settings['envelopeSender'];
		} elseif (!empty($settings['smtpUsername'])) {
			$smtpFrom = $settings['smtpUsername'];
		}

		if ($smtpFrom) {
			// Use SMTP-compatible From address
			$displayName = $originalFromName ?: '';
			$mailer->setFrom($smtpFrom, $displayName);

			// Set original OJS From as Reply-To (so replies go to the right person)
			if ($originalFromEmail && $originalFromEmail !== $smtpFrom) {
				$mailer->addReplyTo($originalFromEmail, $originalFromName);
			}
		} elseif ($originalFromEmail) {
			// No SMTP credentials known, use original From as-is
			$mailer->setFrom($originalFromEmail, $originalFromName);
		} else {
			// Last resort fallback
			$mailer->setFrom('noreply@localhost', '');
		}

		// ---- ENVELOPE SENDER ----
		if (!empty($settings['envelopeSender']) && !empty($settings['forceEnvelopeSender'])) {
			$mailer->Sender = $settings['envelopeSender'];
		} elseif ($smtpFrom) {
			$mailer->Sender = $smtpFrom;
		}

		// ---- RECIPIENTS (To) ----
		$recipients = $mail->getRecipients();
		if (is_array($recipients)) {
			foreach ($recipients as $recipient) {
				$email = isset($recipient['email']) ? $recipient['email'] : '';
				$name = isset($recipient['name']) ? $recipient['name'] : '';
				if ($email) {
					$mailer->addAddress($email, $name);
				}
			}
		}

		// ---- CC ----
		$ccs = $mail->getCcs();
		if (is_array($ccs)) {
			foreach ($ccs as $cc) {
				$email = isset($cc['email']) ? $cc['email'] : '';
				$name = isset($cc['name']) ? $cc['name'] : '';
				if ($email) {
					$mailer->addCC($email, $name);
				}
			}
		}

		// ---- BCC ----
		$bccs = $mail->getBccs();
		if (is_array($bccs)) {
			foreach ($bccs as $bcc) {
				$email = isset($bcc['email']) ? $bcc['email'] : '';
				$name = isset($bcc['name']) ? $bcc['name'] : '';
				if ($email) {
					$mailer->addBCC($email, $name);
				}
			}
		}

		// ---- REPLY-TO ----
		// Note: We may have already set the original OJS From as Reply-To above.
		// Only add additional Reply-To addresses from the mail object.
		if (method_exists($mail, 'getReplyTo')) {
			$replyTos = $mail->getReplyTo();
			if (is_array($replyTos)) {
				foreach ($replyTos as $replyTo) {
					$email = isset($replyTo['email']) ? $replyTo['email'] : '';
					$name = isset($replyTo['name']) ? $replyTo['name'] : '';
					if ($email && $email !== $originalFromEmail) {
						$mailer->addReplyTo($email, $name);
					}
				}
			}
		}

		// ---- SUBJECT ----
		$mailer->Subject = $mail->getSubject();

		// ---- BODY ----
		$body = $mail->getBody();
		$contentType = method_exists($mail, 'getContentType') ? $mail->getContentType() : null;

		if ($contentType === 'text/html' || strpos($body, '<') !== false) {
			$mailer->isHTML(true);
			$mailer->Body = $body;
			// Generate plain text alternative
			$mailer->AltBody = strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>'), "\n", $body));
		} else {
			$mailer->isHTML(false);
			$mailer->Body = $body;
		}

		// ---- ATTACHMENTS ----
		if (method_exists($mail, 'getAttachments')) {
			$attachments = $mail->getAttachments();
			if (is_array($attachments)) {
				foreach ($attachments as $attachment) {
					if (isset($attachment['path']) && file_exists($attachment['path'])) {
						$filename = isset($attachment['filename']) ? $attachment['filename'] : basename($attachment['path']);
						$contentType = isset($attachment['content-type']) ? $attachment['content-type'] : 'application/octet-stream';
						$mailer->addAttachment($attachment['path'], $filename, 'base64', $contentType);
					}
				}
			}
		}

		// ---- ADDITIONAL HEADERS ----
		if (method_exists($mail, 'getHeaders')) {
			$headers = $mail->getHeaders();
			if (is_array($headers)) {
				foreach ($headers as $header) {
					if (isset($header['name']) && isset($header['value'])) {
						$mailer->addCustomHeader($header['name'], $header['value']);
					}
				}
			}
		}
	}

	// =========================================================================
	// SETTINGS LOAD / SAVE (public for handler access)
	// =========================================================================

	/**
	 * Load all mail settings for a given context
	 *
	 * @param $contextId int
	 * @return array|null
	 */
	public function loadSettings($contextId) {
		$configSource = $this->getSetting($contextId, 'configSource');
		if (!$configSource) {
			return null;
		}

		return array(
			'configSource' => $configSource,
			'mailMethod' => $this->getSetting($contextId, 'mailMethod') ?: 'smtp',
			'smtpServer' => $this->getSetting($contextId, 'smtpServer') ?: '',
			'smtpPort' => $this->getSetting($contextId, 'smtpPort') ?: '587',
			'smtpEncryption' => $this->getSetting($contextId, 'smtpEncryption') ?: 'tls',
			'smtpUsername' => $this->getSetting($contextId, 'smtpUsername') ?: '',
			'smtpPassword' => $this->getSetting($contextId, 'smtpPassword') ?: '',
			'smtpAuthType' => $this->getSetting($contextId, 'smtpAuthType') ?: '',
			'envelopeSender' => $this->getSetting($contextId, 'envelopeSender') ?: '',
			'forceEnvelopeSender' => (bool)$this->getSetting($contextId, 'forceEnvelopeSender'),
			'dmarcCompliant' => (bool)$this->getSetting($contextId, 'dmarcCompliant'),
			'dmarcDisplayName' => $this->getSetting($contextId, 'dmarcDisplayName') ?: '%n via %s',
			'suppressCertCheck' => (bool)$this->getSetting($contextId, 'suppressCertCheck'),
		);
	}

	/**
	 * Save all mail settings for a given context
	 *
	 * @param $contextId int
	 * @param $settings array
	 */
	public function saveSettings($contextId, $settings) {
		$settingKeys = array(
			'configSource', 'mailMethod', 'smtpServer', 'smtpPort',
			'smtpEncryption', 'smtpUsername', 'smtpPassword', 'smtpAuthType',
			'envelopeSender', 'forceEnvelopeSender', 'dmarcCompliant',
			'dmarcDisplayName', 'suppressCertCheck',
		);

		foreach ($settingKeys as $key) {
			if (isset($settings[$key])) {
				$value = $settings[$key];
				if ($key === 'smtpPassword' && !empty($value)) {
					$value = $this->encryptPassword($value);
				}
				$this->updateSetting($contextId, $key, $value);
			}
		}
	}

	// =========================================================================
	// MAIL STATISTICS
	// =========================================================================

	/**
	 * Increment daily mail counter for a context
	 *
	 * @param $contextId int
	 */
	private function _incrementMailCounter($contextId) {
		try {
			$today = date('Y-m-d');
			$stats = $this->getSetting($contextId, 'mailStats');
			$stats = $stats ? json_decode($stats, true) : array();

			if (!is_array($stats)) {
				$stats = array();
			}

			// Increment today's count
			if (isset($stats[$today])) {
				$stats[$today]++;
			} else {
				$stats[$today] = 1;
			}

			// Keep only last 30 days to prevent bloat
			$cutoff = date('Y-m-d', strtotime('-30 days'));
			foreach (array_keys($stats) as $date) {
				if ($date < $cutoff) {
					unset($stats[$date]);
				}
			}

			$this->updateSetting($contextId, 'mailStats', json_encode($stats));
		} catch (Exception $e) {
			// Don't let stats tracking break mail sending
			error_log('MailSettings Plugin stats error: ' . $e->getMessage());
		}
	}

	/**
	 * Get mail statistics for a context
	 *
	 * @param $contextId int
	 * @return array with keys: today, week, month
	 */
	public function getMailStats($contextId) {
		$stats = $this->getSetting($contextId, 'mailStats');
		$stats = $stats ? json_decode($stats, true) : array();

		if (!is_array($stats)) {
			$stats = array();
		}

		$today = date('Y-m-d');
		$weekAgo = date('Y-m-d', strtotime('-7 days'));

		$todayCount = isset($stats[$today]) ? (int)$stats[$today] : 0;
		$weekCount = 0;
		$monthCount = 0;

		foreach ($stats as $date => $count) {
			$monthCount += (int)$count;
			if ($date >= $weekAgo) {
				$weekCount += (int)$count;
			}
		}

		return array(
			'today' => $todayCount,
			'week' => $weekCount,
			'month' => $monthCount,
		);
	}

	// =========================================================================
	// ACCESS CONTROL (public for handler access)
	// =========================================================================

	public function userHasAccess($user, $contextId) {
		if (!$user || !$contextId || $contextId === CONTEXT_SITE) {
			return false;
		}

		$roleDao = DAORegistry::getDAO('RoleDAO');

		// Site Administrators can access any journal's settings
		if ($roleDao->userHasRole(CONTEXT_SITE, $user->getId(), ROLE_ID_SITE_ADMIN)) {
			return true;
		}

		// Check for Journal Manager specifically (NOT Journal Editor)
		// In OJS 3.3, both "Journal manager" and "Journal editor" share ROLE_ID_MANAGER.
		// We need user_group level check to distinguish them.
		// The Journal Manager group is the first ROLE_ID_MANAGER group created for each context.
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		// Get all ROLE_ID_MANAGER groups for this context
		$managerGroups = $userGroupDao->getByRoleId($contextId, ROLE_ID_MANAGER);
		$journalManagerGroupId = null;

		while ($group = $managerGroups->next()) {
			$abbrev = strtolower($group->getLocalizedAbbrev());
			$name = strtolower($group->getLocalizedName());

			// Identify Journal Manager by name/abbrev (works for EN and TR defaults)
			if (strpos($name, 'manager') !== false || strpos($name, 'yönetici') !== false
				|| strpos($abbrev, 'jm') !== false) {
				$journalManagerGroupId = $group->getId();
				break;
			}
		}

		// Fallback: if no group matched by name, use the first ROLE_ID_MANAGER group
		// (Journal Manager is always created before Journal Editor in default OJS)
		if (!$journalManagerGroupId) {
			$managerGroups = $userGroupDao->getByRoleId($contextId, ROLE_ID_MANAGER);
			if ($firstGroup = $managerGroups->next()) {
				$journalManagerGroupId = $firstGroup->getId();
			}
		}

		if ($journalManagerGroupId) {
			return $userGroupDao->userInGroup($user->getId(), $journalManagerGroupId);
		}

		return false;
	}

	// =========================================================================
	// SECURITY / ENCRYPTION (public for handler access)
	// =========================================================================

	public function encryptPassword($password) {
		$key = $this->_getEncryptionKey();
		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
		$encrypted = openssl_encrypt($password, 'aes-256-cbc', $key, 0, $iv);
		return base64_encode($iv . '::' . $encrypted);
	}

	public function decryptPassword($encryptedPassword) {
		if (empty($encryptedPassword)) {
			return '';
		}

		$key = $this->_getEncryptionKey();
		$data = base64_decode($encryptedPassword);

		if ($data === false || strpos($data, '::') === false) {
			return $encryptedPassword;
		}

		$parts = explode('::', $data, 2);
		if (count($parts) !== 2) {
			return $encryptedPassword;
		}

		$decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
		return ($decrypted !== false) ? $decrypted : $encryptedPassword;
	}

	private function _getEncryptionKey() {
		$dbPassword = Config::getVar('database', 'password');
		$salt = Config::getVar('general', 'installed') ? 'mailSettings_v1' : 'ms_fallback';
		return hash('sha256', $dbPassword . $salt, true);
	}

	// =========================================================================
	// TEST MAIL (public for handler access)
	// =========================================================================

	public function sendTestEmail($testEmail, $contextId) {
		$steps = array();
		$settings = $this->_getEffectiveSettings($contextId);

		if (!$settings) {
			$steps[] = array('status' => 'info', 'message' => __('plugins.generic.mailSettings.test.usingDefault'));
		}

		// Find PHPMailer class
		$phpMailerClass = $this->_loadPhpMailerClass();

		if (!$phpMailerClass) {
			return array(
				'success' => false,
				'steps' => array(array('status' => 'error', 'message' => __('plugins.generic.mailSettings.test.phpmailerNotFound'))),
			);
		}

		try {
			$mailer = new $phpMailerClass(true);

			if ($settings && $settings['configSource'] === 'custom') {
				// Use shared transport configuration
				$this->_configureMailerTransport($mailer, $settings);

				$mailMethod = isset($settings['mailMethod']) ? $settings['mailMethod'] : 'smtp';
				if ($mailMethod === 'smtp') {
					$steps[] = array(
						'status' => 'info',
						'message' => __('plugins.generic.mailSettings.test.connecting',
							array('server' => $settings['smtpServer'], 'port' => $settings['smtpPort']))
					);
				} elseif ($mailMethod === 'phpmail') {
					$steps[] = array('status' => 'info', 'message' => __('plugins.generic.mailSettings.test.usingPhpMail'));
				} elseif ($mailMethod === 'sendmail') {
					$steps[] = array('status' => 'info', 'message' => __('plugins.generic.mailSettings.test.usingSendmail'));
				}
			} else {
				$smtp = Config::getVar('email', 'smtp');
				if ($smtp) {
					$mailer->isSMTP();
					$mailer->Host = Config::getVar('email', 'smtp_server');
					$mailer->Port = Config::getVar('email', 'smtp_port', 25);
					$smtpAuth = Config::getVar('email', 'smtp_auth');
					if ($smtpAuth) {
						$mailer->SMTPAuth = true;
						$mailer->SMTPSecure = $smtpAuth;
						$mailer->Username = Config::getVar('email', 'smtp_username');
						$mailer->Password = Config::getVar('email', 'smtp_password');
					}
					$steps[] = array(
						'status' => 'info',
						'message' => __('plugins.generic.mailSettings.test.connecting',
							array('server' => $mailer->Host, 'port' => $mailer->Port))
					);
				} else {
					$mailer->isMail();
					$steps[] = array('status' => 'info', 'message' => __('plugins.generic.mailSettings.test.usingPhpMail'));
				}
			}

			$siteName = Config::getVar('general', 'installed')
				? Application::get()->getRequest()->getSite()->getLocalizedTitle()
				: 'OJS';
			$fromEmail = ($settings && !empty($settings['envelopeSender']))
				? $settings['envelopeSender']
				: (($settings && !empty($settings['smtpUsername']))
					? $settings['smtpUsername']
					: Config::getVar('email', 'default_envelope_sender', 'pkp@pkp.sfu.ca'));

			$mailer->setFrom($fromEmail, $siteName . ' - Mail Test');
			$mailer->addAddress($testEmail);
			$mailer->isHTML(true);
			$mailer->CharSet = Config::getVar('i18n', 'client_charset', 'utf-8');
			$mailer->Subject = __('plugins.generic.mailSettings.test.subject', array('siteName' => $siteName));
			$mailer->Body = $this->_getTestEmailBody($siteName, $settings);
			$mailer->AltBody = __('plugins.generic.mailSettings.test.bodyPlain', array('siteName' => $siteName));

			$steps[] = array('status' => 'info', 'message' => __('plugins.generic.mailSettings.test.authenticating'));

			$mailer->send();

			$steps[] = array('status' => 'success', 'message' => __('plugins.generic.mailSettings.test.authSuccess'));
			$steps[] = array('status' => 'success', 'message' => __('plugins.generic.mailSettings.test.sendSuccess', array('email' => $testEmail)));

			return array(
				'success' => true,
				'steps' => $steps,
				'message' => __('plugins.generic.mailSettings.test.allPassed'),
			);

		} catch (\Exception $e) {
			$steps[] = array('status' => 'error', 'message' => $e->getMessage());
			$suggestion = $this->_getErrorSuggestion($e->getMessage(), $settings);
			if ($suggestion) {
				$steps[] = array('status' => 'info', 'message' => $suggestion);
			}
			return array(
				'success' => false,
				'steps' => $steps,
				'message' => __('plugins.generic.mailSettings.test.failed'),
			);
		}
	}

	private function _getTestEmailBody($siteName, $settings) {
		$method = ($settings) ? ($settings['mailMethod'] ?? 'smtp') : 'config default';
		$server = ($settings && isset($settings['smtpServer'])) ? $settings['smtpServer'] : 'config.inc.php';

		$html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
		$html .= '<div style="background: #004165; color: white; padding: 20px; border-radius: 4px 4px 0 0;">';
		$html .= '<h2 style="margin:0;">&#10004; ' . __('plugins.generic.mailSettings.test.emailTitle') . '</h2>';
		$html .= '</div>';
		$html .= '<div style="border: 1px solid #ddd; border-top: none; padding: 20px; border-radius: 0 0 4px 4px;">';
		$html .= '<p>' . __('plugins.generic.mailSettings.test.emailBody', array('siteName' => $siteName)) . '</p>';
		$html .= '<table style="width:100%; border-collapse:collapse; margin-top:15px;">';
		$html .= '<tr><td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . __('plugins.generic.mailSettings.test.method') . '</td><td style="padding:8px; border-bottom:1px solid #eee;">' . htmlspecialchars(strtoupper($method)) . '</td></tr>';
		$html .= '<tr><td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . __('plugins.generic.mailSettings.test.server') . '</td><td style="padding:8px; border-bottom:1px solid #eee;">' . htmlspecialchars($server) . '</td></tr>';
		$html .= '<tr><td style="padding:8px; font-weight:bold;">' . __('plugins.generic.mailSettings.test.time') . '</td><td style="padding:8px;">' . gmdate('Y-m-d H:i:s') . ' (UTC)</td></tr>';
		$html .= '</table>';
		$html .= '<p style="margin-top:20px; color: #666; font-size: 12px;">' . __('plugins.generic.mailSettings.test.footer') . '</p>';
		$html .= '</div></div>';

		return $html;
	}

	private function _getErrorSuggestion($errorMessage, $settings) {
		$errorLower = strtolower($errorMessage);

		if (strpos($errorLower, 'authentication') !== false || strpos($errorLower, 'password') !== false) {
			if ($settings && strpos($settings['smtpServer'] ?? '', 'gmail') !== false) {
				return __('plugins.generic.mailSettings.test.suggestion.gmail');
			}
			if ($settings && strpos($settings['smtpServer'] ?? '', 'office365') !== false) {
				return __('plugins.generic.mailSettings.test.suggestion.office365');
			}
			return __('plugins.generic.mailSettings.test.suggestion.auth');
		}

		if (strpos($errorLower, 'connect') !== false || strpos($errorLower, 'timeout') !== false) {
			return __('plugins.generic.mailSettings.test.suggestion.connection');
		}

		if (strpos($errorLower, 'certificate') !== false || strpos($errorLower, 'ssl') !== false) {
			return __('plugins.generic.mailSettings.test.suggestion.ssl');
		}

		return null;
	}

	// =========================================================================
	// PRESETS
	// =========================================================================

	public static function getPresets() {
		return array(
			'gmail' => array(
				'name' => 'Gmail / Google Workspace',
				'server' => 'smtp.gmail.com',
				'port' => '587',
				'encryption' => 'tls',
				'authType' => '',
				'note' => 'plugins.generic.mailSettings.preset.gmail.note',
			),
			'office365' => array(
				'name' => 'Office 365 / Outlook',
				'server' => 'smtp.office365.com',
				'port' => '587',
				'encryption' => 'tls',
				'authType' => '',
				'note' => 'plugins.generic.mailSettings.preset.office365.note',
			),
			'yandex' => array(
				'name' => 'Yandex Mail',
				'server' => 'smtp.yandex.com',
				'port' => '465',
				'encryption' => 'ssl',
				'authType' => '',
				'note' => '',
			),
			'zoho' => array(
				'name' => 'Zoho Mail',
				'server' => 'smtp.zoho.com',
				'port' => '465',
				'encryption' => 'ssl',
				'authType' => '',
				'note' => '',
			),
			'custom' => array(
				'name' => 'Custom SMTP',
				'server' => '',
				'port' => '587',
				'encryption' => 'tls',
				'authType' => '',
				'note' => '',
			),
		);
	}
}
