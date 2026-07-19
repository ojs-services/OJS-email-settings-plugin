<?php

/**
 * @file plugins/generic/mailSettings/MailSettingsHandler.inc.php
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MailSettingsHandler
 * @ingroup plugins_generic_mailSettings
 *
 * @brief Handler for the Email Settings standalone page
 */

import('classes.handler.Handler');

class MailSettingsHandler extends Handler {

	/** @var MailSettingsPlugin */
	private $_plugin;

	/** @var int Minimum seconds between test emails */
	const TEST_EMAIL_COOLDOWN = 15;

	/** @var int Maximum SMTP server hostname length */
	const MAX_SERVER_LENGTH = 255;

	/** @var int Maximum username length */
	const MAX_USERNAME_LENGTH = 255;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->_plugin = PluginRegistry::getPlugin('generic', 'mailsettingsplugin');
		$this->_isBackendPage = true;

		// Allow Site Admin and Journal Manager to access all operations
		$this->addRoleAssignment(
			array(ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER),
			array('index', 'save', 'testMail')
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	// =========================================================================
	// MAIN SETTINGS PAGE
	// =========================================================================

	/**
	 * Display the mail settings page
	 */
	public function index($args, $request) {
		$plugin = $this->_plugin;
		$context = $request->getContext();

		// Plugin only works at journal level
		if (!$context) {
			$request->redirect(null, 'index');
			return;
		}

		$contextId = $context->getId();
		$user = $request->getUser();

		// Check access
		if (!$plugin->userHasAccess($user, $contextId)) {
			$request->redirect(null, 'index');
			return;
		}

		$templateMgr = TemplateManager::getManager($request);
		$this->setupTemplate($request);

		$dispatcher = $request->getDispatcher();

		// CSRF token for AJAX requests
		$session = $request->getSession();
		$csrfToken = $session ? $session->getCSRFToken() : '';

		// Configuration handed to the page JS as a JSON data island.
		// JSON_HEX_TAG makes the payload safe inside a <script> element.
		$jsConfig = array(
			'saveUrl' => $dispatcher->url($request, ROUTE_PAGE, null, 'mailSettings', 'save'),
			'testMailUrl' => $dispatcher->url($request, ROUTE_PAGE, null, 'mailSettings', 'testMail'),
			'csrfToken' => $csrfToken,
			'i18n' => array(
				'statusCustom' => __('plugins.generic.mailSettings.status.custom'),
				'statusDefault' => __('plugins.generic.mailSettings.status.default'),
				'enterEmail' => __('plugins.generic.mailSettings.test.invalidEmail'),
				'sending' => __('plugins.generic.mailSettings.test.sending'),
				'parseError' => __('plugins.generic.mailSettings.error.parseError'),
				'showPassword' => __('plugins.generic.mailSettings.smtp.showPassword'),
				'hidePassword' => __('plugins.generic.mailSettings.smtp.hidePassword'),
				'noteGmail' => __('plugins.generic.mailSettings.preset.gmail.note'),
				'noteOffice365' => __('plugins.generic.mailSettings.preset.office365.note'),
			),
		);

		// Assign template variables
		$templateMgr->assign(array(
			'pageTitle' => __('plugins.generic.mailSettings.displayName'),
			'pluginName' => $plugin->getName(),
			'presets' => MailSettingsPlugin::getPresets(),
			'pluginJsUrl' => $request->getBaseUrl() . '/' . $plugin->getPluginPath() . '/js/mailSettings.js',
			'pluginCssUrl' => $request->getBaseUrl() . '/' . $plugin->getPluginPath() . '/css/mailSettings.css',
			'mailSettingsConfigJson' => json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
		));

		// Load current settings
		$this->_assignCurrentSettings($templateMgr, $plugin, $contextId);

		// Access group configuration (manager-role groups of this context)
		$configuredGroups = $plugin->getSetting($contextId, 'accessUserGroups');
		$configuredGroups = is_array($configuredGroups) ? array_map('intval', $configuredGroups) : array();
		$defaultManagerGroupId = (int)$plugin->detectJournalManagerGroupId($contextId);
		$accessGroups = array();
		foreach ($plugin->getManagerGroups($contextId) as $groupId => $groupName) {
			$accessGroups[] = array(
				'id' => $groupId,
				'name' => $groupName,
				'checked' => $configuredGroups
					? in_array($groupId, $configuredGroups, true)
					: ($groupId === $defaultManagerGroupId),
			);
		}
		$templateMgr->assign('accessGroups', $accessGroups);

		// Load mail statistics
		$mailStats = $plugin->getMailStats($contextId);
		$templateMgr->assign('mailStats', $mailStats);

		$templateMgr->display($plugin->getTemplateResource('settingsPage.tpl'));
	}

	// =========================================================================
	// SAVE SETTINGS (AJAX)
	// =========================================================================

	/**
	 * Save mail settings via AJAX
	 */
	public function save($args, $request) {
		// CSRF protection
		if (!$request->checkCSRF()) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.csrf'));
		}

		$plugin = $this->_plugin;
		$context = $request->getContext();

		if (!$context) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.noAccess'));
		}

		$contextId = $context->getId();
		$user = $request->getUser();

		if (!$plugin->userHasAccess($user, $contextId)) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.noAccess'));
		}

		// Access groups: validate against the context's manager-role groups.
		// An empty selection means "use the default Journal Manager rule".
		$selectedGroups = $request->getUserVar('accessUserGroups');
		if (!is_array($selectedGroups)) {
			$selectedGroups = array();
		}
		$managerGroups = $plugin->getManagerGroups($contextId);
		$accessUserGroups = array();
		foreach ($selectedGroups as $groupId) {
			$groupId = (int)$groupId;
			if (isset($managerGroups[$groupId]) && !in_array($groupId, $accessUserGroups, true)) {
				$accessUserGroups[] = $groupId;
			}
		}

		// Lockout guard: a non-admin cannot save a selection that would
		// remove their own access to this page.
		if (!empty($accessUserGroups)) {
			$roleDao = DAORegistry::getDAO('RoleDAO');
			if (!$roleDao->userHasRole(CONTEXT_SITE, $user->getId(), ROLE_ID_SITE_ADMIN)) {
				$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
				$stillHasAccess = false;
				foreach ($accessUserGroups as $groupId) {
					if ($userGroupDao->userInGroup($user->getId(), $groupId)) {
						$stillHasAccess = true;
						break;
					}
				}
				if (!$stillHasAccess) {
					return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.accessLockout'));
				}
			}
		}

		// Read configSource first
		$configSource = $request->getUserVar('configSource');
		if (!in_array($configSource, array('default', 'custom'), true)) {
			$configSource = 'default';
		}

		// When switching to "default", only save the configSource toggle
		// (plus the access group selection, which applies to both modes).
		if ($configSource === 'default') {
			$plugin->saveSettings($contextId, array(
				'configSource' => 'default',
				'accessUserGroups' => $accessUserGroups,
			));
			return $this->_jsonResponse(true, __('plugins.generic.mailSettings.settingsSaved'));
		}

		// configSource = custom: validate and save all settings
		$mailMethod = $request->getUserVar('mailMethod');
		if (!in_array($mailMethod, array('smtp', 'phpmail', 'sendmail'), true)) {
			$mailMethod = 'smtp';
		}

		$smtpServer = $this->_sanitizeHostname(trim($request->getUserVar('smtpServer') ?: ''));
		$smtpPort = $this->_validatePort($request->getUserVar('smtpPort'));
		$smtpEncryption = $request->getUserVar('smtpEncryption');
		if (!in_array($smtpEncryption, array('tls', 'ssl', 'none'), true)) {
			$smtpEncryption = 'tls';
		}

		$smtpUsername = $this->_sanitizeString(trim($request->getUserVar('smtpUsername') ?: ''), self::MAX_USERNAME_LENGTH);
		$smtpAuthType = $request->getUserVar('smtpAuthType');
		if (!in_array($smtpAuthType, array('', 'LOGIN', 'PLAIN', 'CRAM-MD5'), true)) {
			$smtpAuthType = '';
		}

		$envelopeSender = trim($request->getUserVar('envelopeSender') ?: '');
		if ($envelopeSender !== '' && !filter_var($envelopeSender, FILTER_VALIDATE_EMAIL)) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.invalidEnvelopeSender'));
		}

		$dmarcDisplayName = $this->_sanitizeString($request->getUserVar('dmarcDisplayName') ?: '%n via %s', 100);

		// SMTP validation
		if ($mailMethod === 'smtp' && empty($smtpServer)) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.smtpServerRequired'));
		}

		$settings = array(
			'configSource' => 'custom',
			'mailMethod' => $mailMethod,
			'smtpServer' => $smtpServer,
			'smtpPort' => (string)$smtpPort,
			'smtpEncryption' => $smtpEncryption,
			'smtpUsername' => $smtpUsername,
			'smtpAuthType' => $smtpAuthType,
			'envelopeSender' => $envelopeSender,
			'forceEnvelopeSender' => (bool)$request->getUserVar('forceEnvelopeSender'),
			'dmarcCompliant' => (bool)$request->getUserVar('dmarcCompliant'),
			'dmarcDisplayName' => $dmarcDisplayName,
			'suppressCertCheck' => (bool)$request->getUserVar('suppressCertCheck'),
			'accessUserGroups' => $accessUserGroups,
		);

		// Handle password
		$newPassword = $request->getUserVar('smtpPassword');
		if (!empty($newPassword)) {
			$settings['smtpPassword'] = $newPassword;
		}

		$plugin->saveSettings($contextId, $settings);
		return $this->_jsonResponse(true, __('plugins.generic.mailSettings.settingsSaved'));
	}

	// =========================================================================
	// TEST MAIL (AJAX)
	// =========================================================================

	/**
	 * Send test email via AJAX
	 */
	public function testMail($args, $request) {
		// CSRF protection
		if (!$request->checkCSRF()) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.csrf'));
		}

		$plugin = $this->_plugin;
		$context = $request->getContext();

		if (!$context) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.noAccess'));
		}

		$contextId = $context->getId();
		$user = $request->getUser();

		if (!$plugin->userHasAccess($user, $contextId)) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.error.noAccess'));
		}

		$testEmail = trim($request->getUserVar('testEmail') ?: '');
		if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.test.invalidEmail'));
		}

		// Length check
		if (strlen($testEmail) > self::MAX_USERNAME_LENGTH) {
			return $this->_jsonResponse(false, __('plugins.generic.mailSettings.test.invalidEmail'));
		}

		// Rate limiting: prevent test email spam. Runs after validation so a
		// rejected (e.g. mistyped) address does not consume the cooldown.
		$session = $request->getSession();
		if ($session) {
			$lastTestTime = $session->getSessionVar('mailSettings_lastTest');
			$now = time();
			if ($lastTestTime && ($now - $lastTestTime) < self::TEST_EMAIL_COOLDOWN) {
				$remaining = self::TEST_EMAIL_COOLDOWN - ($now - $lastTestTime);
				return $this->_jsonResponse(false,
					__('plugins.generic.mailSettings.test.rateLimited', array('seconds' => $remaining))
				);
			}
			$session->setSessionVar('mailSettings_lastTest', $now);
		}

		$result = $plugin->sendTestEmail($testEmail, $contextId);

		// Sanitize PHPMailer error messages to avoid leaking server internals
		if (isset($result['steps']) && is_array($result['steps'])) {
			foreach ($result['steps'] as &$step) {
				if (isset($step['status']) && $step['status'] === 'error') {
					$step['message'] = $this->_sanitizeErrorMessage($step['message']);
				}
			}
			unset($step);
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		exit;
	}

	// =========================================================================
	// VALIDATION & SANITIZATION HELPERS
	// =========================================================================

	/**
	 * Sanitize hostname - allow only valid hostname characters
	 */
	private function _sanitizeHostname($hostname) {
		// Remove anything that isn't a valid hostname character
		$hostname = preg_replace('/[^a-zA-Z0-9.\-]/', '', $hostname);
		// Limit length
		return substr($hostname, 0, self::MAX_SERVER_LENGTH);
	}

	/**
	 * Validate port number (1-65535)
	 */
	private function _validatePort($port) {
		$port = (int)$port;
		if ($port < 1 || $port > 65535) {
			return 587; // default
		}
		return $port;
	}

	/**
	 * Sanitize a general string input
	 */
	private function _sanitizeString($str, $maxLength = 255) {
		// Strip control characters
		$str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
		return substr($str, 0, $maxLength);
	}

	/**
	 * Sanitize error messages from PHPMailer to avoid leaking
	 * sensitive server information (IPs, paths, version numbers).
	 */
	private function _sanitizeErrorMessage($message) {
		if (empty($message)) return '';

		// Strip Unix paths, but keep URLs intact (the lookbehind refuses a
		// slash preceded by ":" or another "/", i.e. the "://" of a URL)
		$message = preg_replace('#(?<![:/\w])/[^\s:*?"<>|]+#', '[path]', $message);

		// Strip Windows paths (C:\... or C:/...)
		$message = preg_replace('#\b[A-Za-z]:[\\\\/][^\s*?"<>|]+#', '[path]', $message);

		// Limit length
		return substr($message, 0, 500);
	}

	// =========================================================================
	// RESPONSE HELPERS
	// =========================================================================

	/**
	 * Send a JSON response and exit
	 */
	private function _jsonResponse($success, $message) {
		header('Content-Type: application/json');
		echo json_encode(array(
			'success' => (bool)$success,
			'message' => $message,
		));
		exit;
	}

	// =========================================================================
	// TEMPLATE HELPERS
	// =========================================================================

	/**
	 * Assign current settings to template
	 */
	private function _assignCurrentSettings($templateMgr, $plugin, $contextId) {
		$templateMgr->assign(array(
			'configSource' => $plugin->getSetting($contextId, 'configSource') ?: 'default',
			'mailMethod' => $plugin->getSetting($contextId, 'mailMethod') ?: 'smtp',
			'smtpServer' => $plugin->getSetting($contextId, 'smtpServer') ?: '',
			'smtpPort' => $plugin->getSetting($contextId, 'smtpPort') ?: '587',
			'smtpEncryption' => $plugin->getSetting($contextId, 'smtpEncryption') ?: 'tls',
			'smtpUsername' => $plugin->getSetting($contextId, 'smtpUsername') ?: '',
			'smtpAuthType' => $plugin->getSetting($contextId, 'smtpAuthType') ?: '',
			'envelopeSender' => $plugin->getSetting($contextId, 'envelopeSender') ?: '',
			'forceEnvelopeSender' => (bool)$plugin->getSetting($contextId, 'forceEnvelopeSender'),
			'dmarcCompliant' => (bool)$plugin->getSetting($contextId, 'dmarcCompliant'),
			'dmarcDisplayName' => $plugin->getSetting($contextId, 'dmarcDisplayName') ?: '%n via %s',
			'suppressCertCheck' => (bool)$plugin->getSetting($contextId, 'suppressCertCheck'),
			'hasPassword' => !empty($plugin->getSetting($contextId, 'smtpPassword')),
		));
	}
}
