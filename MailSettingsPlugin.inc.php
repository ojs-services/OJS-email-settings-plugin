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

use Illuminate\Database\Capsule\Manager as Capsule;

import('lib.pkp.classes.plugins.GenericPlugin');

class MailSettingsPlugin extends GenericPlugin {

	/** @var array Per-request cache for userHasAccess() results, keyed by "userId:contextId" */
	private $_accessCache = array();

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
	 * Add a link to the OJS backend sidebar for admin/manager users.
	 * Uses unified sidebar pattern shared across all OJS Services plugins.
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 */
	public function addSidebarLink($hookName, $args) {
		$templateMgr = $args[0];

		// Backend detection: setupBackendPage() populates the 'menu' Vue
		// state on every backend handler (core pages AND all plugin
		// management pages) and never on the frontend — so no manual
		// whitelist of template paths / page names is needed, and the link
		// no longer disappears on other plugins' management pages.
		$menu = $templateMgr->getState('menu');
		if (!is_array($menu) || empty($menu)) {
			return false;
		}

		// The display hook fires for every template (components, AJAX
		// fragments) — inject the script only once per request. Checked
		// after backend detection so a non-backend display call can never
		// consume the guard.
		static $injected = false;
		if ($injected) {
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
			'(function(){' .
			'var S=window.OJS_SIDEBAR_ITEMS=window.OJS_SIDEBAR_ITEMS||[];' .
			'S.push({id:"mailSettingsSidebarLink",icon:"\\u2709\\uFE0F",text:' . json_encode($menuLabel) . ',url:' . json_encode($url) . ',pg:"mailSettings",p:50});' .
			'if(!window._ojsSbRender){window._ojsSbRender=function(){' .
			'var items=window.OJS_SIDEBAR_ITEMS;if(!items||!items.length)return;' .
			'var nav=document.querySelector(".pkp_nav_list,.app__nav,nav[role=navigation] ul,.pkpNav__list");' .
			'if(!nav)nav=document.querySelector("#navigationPrimary ul,.pkp_navigation_primary ul");' .
			'if(!nav){if(!window._ojsSbR)window._ojsSbR=0;if(window._ojsSbR++<30)setTimeout(window._ojsSbRender,200);return;}' .
			'window._ojsSbR=0;if(nav.tagName==="NAV"){var ul=nav.querySelector("ul");if(ul)nav=ul;}' .
			'var old=nav.querySelectorAll("[data-ojs-sidebar]");for(var i=0;i<old.length;i++)old[i].parentNode.removeChild(old[i]);' .
			'items.sort(function(a,b){return(a.p||99)-(b.p||99);});' .
			'var sep=document.createElement("li");sep.setAttribute("data-ojs-sidebar","1");' .
			'sep.style.cssText="border-top:1px solid rgba(255,255,255,0.15);margin:12px 0 6px;padding:0;list-style:none;";nav.appendChild(sep);' .
			'var u=window.location.href;items.forEach(function(it){' .
			'var li=document.createElement("li");li.id=it.id;li.setAttribute("data-ojs-sidebar","1");' .
			'var a=document.createElement("a");a.className="app__navItem";a.href=it.url;a.innerHTML=it.icon+" "+it.text;' .
			// No inline color: inline styles would override the theme rules
			// (.app__navItem is dark text; --isCurrent flips to white on dark).
			'a.style.cssText="display:flex;align-items:center;gap:8px;padding:8px 16px;text-decoration:none;font-size:0.9em;font-weight:600;";' .
			'if(u.indexOf(it.pg)>-1){a.classList.add("app__navItem--isCurrent");}' .
			'li.appendChild(a);nav.appendChild(li);});};}' .
			'clearTimeout(window._ojsSbT);window._ojsSbT=setTimeout(window._ojsSbRender,100);' .
			'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){clearTimeout(window._ojsSbT);window._ojsSbT=setTimeout(window._ojsSbRender,100);});}' .
			'})();',
			array(
				'inline' => true,
				'contexts' => array('backend')
			)
		);

		$injected = true;

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

		// No request context (CLI/cron scheduled tasks, site-level pages):
		// fall back to the single journal when the installation has exactly
		// one active journal. On multi-journal installations we cannot know
		// which journal the email belongs to, so OJS defaults apply.
		if (!$contextId || $contextId === CONTEXT_SITE) {
			$contextId = $this->_getSingleContextId();
			if (!$contextId) {
				return false;
			}
		}

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
		// Only journal-level settings are supported; callers resolve the
		// context (see overrideMailSettings for the CLI/cron fallback).
		if (!$contextId || $contextId === CONTEXT_SITE) {
			return null;
		}

		$journalSettings = $this->loadSettings($contextId);
		if ($journalSettings && $journalSettings['configSource'] === 'custom') {
			return $journalSettings;
		}

		return null;
	}

	/**
	 * Return the id of the only active journal, or null if the installation
	 * has zero or more than one active journal.
	 *
	 * @return int|null
	 */
	private function _getSingleContextId() {
		try {
			$contextDao = Application::getContextDAO();
			$contexts = $contextDao->getAll(true); // enabled contexts only
			$first = $contexts->next();
			if (!$first || $contexts->next()) {
				return null;
			}
			return $first->getId();
		} catch (Exception $e) {
			error_log('MailSettings Plugin: context lookup failed: ' . $e->getMessage());
			return null;
		}
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
			// Use SMTP-compatible From address.
			// Apply the configured DMARC display-name format so the sender name stays
			// consistent (e.g. "Editorial Office") instead of the acting user's name.
			// %n = original sender name, %s = site/journal name. Falls back to the
			// original sender name when Force-DMARC is off or no format is set.
			$displayName = $originalFromName ?: '';
			if (!empty($settings['dmarcCompliant']) && !empty($settings['dmarcDisplayName'])) {
				$siteName = '';
				if (strpos($settings['dmarcDisplayName'], '%s') !== false && Config::getVar('general', 'installed')) {
					$site = Application::get()->getRequest()->getSite();
					if ($site) $siteName = (string)$site->getLocalizedTitle();
				}
				$displayName = str_replace(array('%n', '%s'), array($originalFromName, $siteName), $settings['dmarcDisplayName']);
			}
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

		// Detect HTML by looking for an actual tag, so plain-text bodies that
		// merely contain "<" (e.g. "Editor <editor@example.com>") stay plain.
		if ($contentType === 'text/html' || preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i', (string)$body)) {
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
			'dmarcDisplayName', 'suppressCertCheck', 'accessUserGroups',
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

	/** @var string Prefix for the per-day counter settings */
	const MAIL_STATS_PREFIX = 'mailStatsDay_';

	/**
	 * Increment daily mail counter for a context.
	 *
	 * Uses an atomic SQL increment on a per-day plugin_settings row instead
	 * of a read-modify-write JSON blob, so concurrent sends never lose counts.
	 *
	 * @param $contextId int
	 */
	private function _incrementMailCounter($contextId) {
		try {
			$isNewDay = $this->_bumpDayCounter($contextId, self::MAIL_STATS_PREFIX . date('Y-m-d'), 1);

			// Prune counters older than 30 days once per day (on first mail of the day)
			if ($isNewDay) {
				Capsule::table('plugin_settings')
					->where('plugin_name', '=', $this->getName())
					->where('context_id', '=', (int)$contextId)
					->where('setting_name', 'like', self::MAIL_STATS_PREFIX . '%')
					->where('setting_name', '<', self::MAIL_STATS_PREFIX . date('Y-m-d', strtotime('-30 days')))
					->delete();
			}
		} catch (Exception $e) {
			// Don't let stats tracking break mail sending
			error_log('MailSettings Plugin stats error: ' . $e->getMessage());
		}
	}

	/**
	 * Atomically add $amount to a per-day counter row, creating it if needed.
	 *
	 * Note: these rows are written with raw SQL on purpose; they are only
	 * ever read back with raw SQL (getMailStats), so the PluginSettingsDAO
	 * file cache never needs to know about them.
	 *
	 * @param $contextId int
	 * @param $settingName string
	 * @param $amount int
	 * @return bool true if a new row was created (first increment of the day)
	 */
	private function _bumpDayCounter($contextId, $settingName, $amount) {
		$where = array(
			array('plugin_name', '=', $this->getName()),
			array('context_id', '=', (int)$contextId),
			array('setting_name', '=', $settingName),
		);

		$affected = Capsule::table('plugin_settings')->where($where)
			->update(array('setting_value' => Capsule::raw('setting_value + ' . (int)$amount)));
		if ($affected) {
			return false;
		}

		try {
			Capsule::table('plugin_settings')->insert(array(
				'plugin_name' => $this->getName(),
				'context_id' => (int)$contextId,
				'setting_name' => $settingName,
				'setting_value' => (string)(int)$amount,
				'setting_type' => 'int',
			));
			return true;
		} catch (Exception $e) {
			// Unique-key race: another process inserted the row first — retry the increment
			Capsule::table('plugin_settings')->where($where)
				->update(array('setting_value' => Capsule::raw('setting_value + ' . (int)$amount)));
			return false;
		}
	}

	/**
	 * Get mail statistics for a context
	 *
	 * @param $contextId int
	 * @return array with keys: today, week, month
	 */
	public function getMailStats($contextId) {
		$today = date('Y-m-d');
		$weekAgo = date('Y-m-d', strtotime('-7 days'));
		$monthAgo = date('Y-m-d', strtotime('-30 days'));

		$todayCount = 0;
		$weekCount = 0;
		$monthCount = 0;

		try {
			$this->_migrateLegacyMailStats($contextId);

			$rows = Capsule::table('plugin_settings')
				->where('plugin_name', '=', $this->getName())
				->where('context_id', '=', (int)$contextId)
				->where('setting_name', 'like', self::MAIL_STATS_PREFIX . '%')
				->get();

			foreach ($rows as $row) {
				$date = substr($row->setting_name, strlen(self::MAIL_STATS_PREFIX));
				$count = (int)$row->setting_value;
				if ($date < $monthAgo) {
					continue;
				}
				$monthCount += $count;
				if ($date >= $weekAgo) {
					$weekCount += $count;
				}
				if ($date === $today) {
					$todayCount = $count;
				}
			}
		} catch (Exception $e) {
			error_log('MailSettings Plugin stats read error: ' . $e->getMessage());
		}

		return array(
			'today' => $todayCount,
			'week' => $weekCount,
			'month' => $monthCount,
		);
	}

	/**
	 * One-time migration of the legacy "mailStats" JSON blob (<= v1.3.x)
	 * into per-day counter rows.
	 *
	 * @param $contextId int
	 */
	private function _migrateLegacyMailStats($contextId) {
		$legacy = $this->getSetting($contextId, 'mailStats');
		if (!$legacy) {
			return;
		}

		$stats = json_decode($legacy, true);
		if (is_array($stats)) {
			foreach ($stats as $date => $count) {
				if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || (int)$count <= 0) {
					continue;
				}
				$this->_bumpDayCounter($contextId, self::MAIL_STATS_PREFIX . $date, (int)$count);
			}
		}

		// Delete via the DAO so the plugin settings cache stays consistent
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
		$pluginSettingsDao->deleteSetting($contextId, $this->getName(), 'mailStats');
	}

	// =========================================================================
	// ACCESS CONTROL (public for handler access)
	// =========================================================================

	public function userHasAccess($user, $contextId) {
		if (!$user || !$contextId || $contextId === CONTEXT_SITE) {
			return false;
		}

		// Cache per request: this runs on every backend page via the
		// TemplateManager::display hook and involves several DB queries.
		$cacheKey = $user->getId() . ':' . $contextId;
		if (array_key_exists($cacheKey, $this->_accessCache)) {
			return $this->_accessCache[$cacheKey];
		}

		return $this->_accessCache[$cacheKey] = $this->_computeUserAccess($user, $contextId);
	}

	/**
	 * Uncached access computation. See userHasAccess().
	 *
	 * @param $user User
	 * @param $contextId int
	 * @return bool
	 */
	private function _computeUserAccess($user, $contextId) {
		$roleDao = DAORegistry::getDAO('RoleDAO');

		// Site Administrators can access any journal's settings
		if ($roleDao->userHasRole(CONTEXT_SITE, $user->getId(), ROLE_ID_SITE_ADMIN)) {
			return true;
		}

		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		// Explicitly configured access groups take precedence. Only manager-role
		// groups of this context are honoured, so a stale or tampered setting
		// can never grant access beyond ROLE_ID_MANAGER.
		$configured = $this->getSetting($contextId, 'accessUserGroups');
		if (is_array($configured) && !empty($configured)) {
			$managerGroups = $this->getManagerGroups($contextId);
			foreach ($configured as $groupId) {
				$groupId = (int)$groupId;
				if (isset($managerGroups[$groupId]) && $userGroupDao->userInGroup($user->getId(), $groupId)) {
					return true;
				}
			}
			return false;
		}

		// Default heuristic: Journal Manager group only (NOT Journal Editor)
		$journalManagerGroupId = $this->detectJournalManagerGroupId($contextId);
		if ($journalManagerGroupId) {
			return (bool)$userGroupDao->userInGroup($user->getId(), $journalManagerGroupId);
		}

		return false;
	}

	/**
	 * Get all ROLE_ID_MANAGER user groups for a context.
	 *
	 * @param $contextId int
	 * @return array groupId => localized group name
	 */
	public function getManagerGroups($contextId) {
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$groups = array();
		$result = $userGroupDao->getByRoleId($contextId, ROLE_ID_MANAGER);
		while ($group = $result->next()) {
			$groups[(int)$group->getId()] = (string)$group->getLocalizedName();
		}
		return $groups;
	}

	/**
	 * Identify the Journal Manager group for a context.
	 *
	 * In OJS 3.3 both "Journal manager" and "Journal editor" share
	 * ROLE_ID_MANAGER; we distinguish them by name/abbrev (EN and TR
	 * defaults), falling back to the first ROLE_ID_MANAGER group, which
	 * default OJS installations create before Journal Editor.
	 *
	 * @param $contextId int
	 * @return int|null
	 */
	public function detectJournalManagerGroupId($contextId) {
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		$managerGroups = $userGroupDao->getByRoleId($contextId, ROLE_ID_MANAGER);
		while ($group = $managerGroups->next()) {
			// (string) casts: getLocalizedAbbrev()/getLocalizedName() may return
			// null, which strtolower() rejects with a deprecation on PHP 8.1
			$abbrev = strtolower((string)$group->getLocalizedAbbrev());
			$name = strtolower((string)$group->getLocalizedName());

			if (strpos($name, 'manager') !== false || strpos($name, 'yönetici') !== false
				|| strpos($abbrev, 'jm') !== false) {
				return $group->getId();
			}
		}

		$managerGroups = $userGroupDao->getByRoleId($contextId, ROLE_ID_MANAGER);
		if ($firstGroup = $managerGroups->next()) {
			return $firstGroup->getId();
		}

		return null;
	}

	// =========================================================================
	// SECURITY / ENCRYPTION (public for handler access)
	// =========================================================================

	public function encryptPassword($password) {
		$key = $this->_getEncryptionKey();
		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
		$encrypted = openssl_encrypt($password, 'aes-256-cbc', $key, 0, $iv);
		if ($encrypted === false) {
			error_log('MailSettings Plugin: password encryption failed.');
			return '';
		}
		// v2 format: the IV is base64-encoded separately. The legacy format
		// glued the raw binary IV to the ciphertext with "::", which the IV
		// itself could randomly contain, silently corrupting decryption.
		return 'v2:' . base64_encode($iv) . ':' . $encrypted;
	}

	public function decryptPassword($encryptedPassword) {
		if (empty($encryptedPassword)) {
			return '';
		}

		$key = $this->_getEncryptionKey();

		// Current format: v2:<base64 iv>:<base64 ciphertext>
		if (strncmp($encryptedPassword, 'v2:', 3) === 0) {
			$parts = explode(':', $encryptedPassword, 3);
			if (count($parts) === 3 && ($iv = base64_decode($parts[1], true)) !== false) {
				$decrypted = openssl_decrypt($parts[2], 'aes-256-cbc', $key, 0, $iv);
				if ($decrypted !== false) {
					return $decrypted;
				}
			}
			error_log('MailSettings Plugin: password decryption failed. If the database password changed, re-enter the SMTP password in the plugin settings.');
			return '';
		}

		// Legacy format (<= v1.3.x): base64(rawIV . '::' . ciphertext)
		$data = base64_decode($encryptedPassword);
		if ($data === false || strpos($data, '::') === false) {
			// Plain-text password stored by very old versions — use as-is
			return $encryptedPassword;
		}

		$parts = explode('::', $data, 2);
		$decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
		if ($decrypted !== false) {
			return $decrypted;
		}

		error_log('MailSettings Plugin: legacy password decryption failed. Re-enter the SMTP password in the plugin settings.');
		return '';
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

			$site = Config::getVar('general', 'installed')
				? Application::get()->getRequest()->getSite()
				: null;
			$siteName = ($site && $site->getLocalizedTitle()) ? $site->getLocalizedTitle() : 'OJS';
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
