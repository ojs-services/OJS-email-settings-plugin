/**
 * plugins/generic/mailSettings/js/mailSettings.js
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Email Settings plugin - settings page interactions.
 *
 * Timing note: this script is loaded with a plain <script src> at the end of
 * the backend page block. The JSON config island is read IMMEDIATELY (during
 * HTML parse, before OJS' Vue app mounts and re-renders the block), while all
 * DOM listeners are attached on DOMContentLoaded (after the mount), so they
 * bind to the final DOM nodes.
 */

(function() {
	'use strict';

	// =========================================================================
	// Config (parsed synchronously — see timing note above)
	// =========================================================================

	var mailSettingsConfig = {};
	(function() {
		var el = document.getElementById('mailSettingsConfigJson');
		if (el) {
			try {
				mailSettingsConfig = JSON.parse(el.textContent) || {};
			} catch (e) {
				// leave defaults; msg() falls back to English strings
			}
		}
	})();

	/**
	 * Translated string lookup with English fallback.
	 */
	function msg(key, fallback) {
		if (mailSettingsConfig.i18n && mailSettingsConfig.i18n[key]) {
			return mailSettingsConfig.i18n[key];
		}
		return fallback;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		setupConfigSourceToggle();
		setupMailMethodToggle();
		setupPresets();
		setupPasswordToggle();
		setupSaveButton();
		setupTestMail();
	}

	// =========================================================================
	// Config Source Toggle (Default / Custom)
	// =========================================================================

	function setupConfigSourceToggle() {
		var radios = document.querySelectorAll('input[name="configSource"]');
		var customPanel = document.getElementById('customSettingsPanel');
		var optionDefault = document.getElementById('optionDefault');
		var optionCustom = document.getElementById('optionCustom');
		var statusBanner = document.getElementById('mailSettingsStatus');
		var statusIcon = document.getElementById('statusIcon');

		if (!radios.length || !customPanel) return;

		radios.forEach(function(radio) {
			radio.addEventListener('change', function() {
				var isCustom = (this.value === 'custom');

				customPanel.classList.toggle('ms-hidden', !isCustom);

				if (optionDefault && optionCustom) {
					optionDefault.classList.toggle('ms-source-active', !isCustom);
					optionCustom.classList.toggle('ms-source-active', isCustom);
				}

				if (statusBanner) {
					statusBanner.classList.toggle('ms-status-custom', isCustom);
					statusBanner.classList.toggle('ms-status-default', !isCustom);
				}

				if (statusIcon) {
					statusIcon.innerHTML = isCustom ? '&#9889;' : '&#128736;';
				}

				var statusText = document.getElementById('statusText');
				if (statusText) {
					statusText.textContent = isCustom
						? msg('statusCustom', 'Using Custom Mail Settings')
						: msg('statusDefault', 'Using OJS Default Configuration (config.inc.php)');
				}
			});
		});
	}

	// =========================================================================
	// Mail Method Toggle
	// =========================================================================

	function setupMailMethodToggle() {
		var methodSelect = document.getElementById('mailMethod');
		var smtpPanel = document.getElementById('smtpSettingsPanel');

		if (!methodSelect || !smtpPanel) return;

		methodSelect.addEventListener('change', function() {
			smtpPanel.classList.toggle('ms-hidden', this.value !== 'smtp');
		});
	}

	// =========================================================================
	// Service Presets
	// =========================================================================

	function setupPresets() {
		var presetCards = document.querySelectorAll('.mailPresetCard');
		var presetNote = document.getElementById('presetNote');

		if (!presetCards.length) return;

		// Store the saved (server-side) values on page load so we can
		// restore them when the user clicks "Custom SMTP"
		var savedValues = {
			smtpServer: (document.getElementById('smtpServer') || {}).value || '',
			smtpPort: (document.getElementById('smtpPort') || {}).value || '587',
			smtpEncryption: (document.getElementById('smtpEncryption') || {}).value || 'tls'
		};

		// Expose a function so the save handler can update savedValues
		window._msUpdateSavedValues = function() {
			savedValues.smtpServer = (document.getElementById('smtpServer') || {}).value || '';
			savedValues.smtpPort = (document.getElementById('smtpPort') || {}).value || '587';
			savedValues.smtpEncryption = (document.getElementById('smtpEncryption') || {}).value || 'tls';
		};

		var presetNotes = {
			'gmail': msg('noteGmail', 'Gmail requires an App Password instead of your regular password. Enable 2-Step Verification first.'),
			'office365': msg('noteOffice365', 'Office 365 requires SMTP AUTH to be enabled for the mailbox.')
		};

		function activatePreset(card) {
			var preset = card.getAttribute('data-preset');
			var server = card.getAttribute('data-server');
			var port = card.getAttribute('data-port');
			var encryption = card.getAttribute('data-encryption');

			presetCards.forEach(function(c) {
				c.classList.remove('ms-preset-active');
				c.setAttribute('aria-pressed', 'false');
			});
			card.classList.add('ms-preset-active');
			card.setAttribute('aria-pressed', 'true');

			// "Custom SMTP" restores the original saved values
			if (preset === 'custom') {
				document.getElementById('smtpServer').value = savedValues.smtpServer;
				document.getElementById('smtpPort').value = savedValues.smtpPort;
				document.getElementById('smtpEncryption').value = savedValues.smtpEncryption;
			} else {
				if (server) document.getElementById('smtpServer').value = server;
				if (port) document.getElementById('smtpPort').value = port;
				if (encryption) document.getElementById('smtpEncryption').value = encryption;
			}

			if (presetNote) {
				var note = presetNotes[preset] || '';
				if (note) {
					presetNote.textContent = note;
					presetNote.style.display = 'block';
				} else {
					presetNote.style.display = 'none';
				}
			}
		}

		presetCards.forEach(function(card) {
			card.addEventListener('click', function() {
				activatePreset(this);
			});
			// Keyboard support: cards are focusable div[role=button]
			card.addEventListener('keydown', function(event) {
				if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
					event.preventDefault();
					activatePreset(this);
				}
			});
		});
	}

	// =========================================================================
	// Password Toggle
	// =========================================================================

	function setupPasswordToggle() {
		var toggleBtn = document.getElementById('togglePassword');
		var passwordField = document.getElementById('smtpPassword');

		if (!toggleBtn || !passwordField) return;

		var showLabel = msg('showPassword', '👁 Show');
		var hideLabel = msg('hidePassword', '🔒 Hide');

		// Hide the toggle button initially — it only makes sense
		// when the user is typing a new password. The saved password
		// is never sent back from the server.
		toggleBtn.style.display = 'none';

		passwordField.addEventListener('input', function() {
			toggleBtn.style.display = this.value.length ? '' : 'none';
			if (!this.value.length) {
				this.type = 'password';
				toggleBtn.textContent = showLabel;
			}
		});

		toggleBtn.addEventListener('click', function() {
			if (passwordField.type === 'password') {
				passwordField.type = 'text';
				this.textContent = hideLabel;
			} else {
				passwordField.type = 'password';
				this.textContent = showLabel;
			}
		});
	}

	// =========================================================================
	// SAVE (AJAX - page stays open)
	// =========================================================================

	function setupSaveButton() {
		var saveBtn = document.getElementById('saveSettingsBtn');
		var form = document.getElementById('mailSettingsForm');

		if (!saveBtn || !form) return;

		// Pressing Enter in a text input must trigger the AJAX save,
		// not a native form submission.
		form.addEventListener('submit', function(event) {
			event.preventDefault();
			saveBtn.click();
		});

		saveBtn.addEventListener('click', function() {
			var formData = new FormData(form);

			// Ensure CSRF token is included
			if (mailSettingsConfig.csrfToken) {
				formData.set('csrfToken', mailSettingsConfig.csrfToken);
			}

			// Handle unchecked checkboxes explicitly
			var checkboxes = ['forceEnvelopeSender', 'dmarcCompliant', 'suppressCertCheck'];
			checkboxes.forEach(function(name) {
				var cb = form.querySelector('input[name="' + name + '"]');
				if (cb && !cb.checked) {
					formData.set(name, '');
				}
			});

			saveBtn.disabled = true;
			var spinner = document.getElementById('saveSpinner');
			if (spinner) spinner.style.display = '';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', mailSettingsConfig.saveUrl, true);

			xhr.onreadystatechange = function() {
				if (xhr.readyState === 4) {
					saveBtn.disabled = false;
					if (spinner) spinner.style.display = 'none';

					try {
						var data = JSON.parse(xhr.responseText);
						showNotification(data.message, data.success);

						// Update saved values so Custom SMTP restores fresh data
						if (data.success && window._msUpdateSavedValues) {
							window._msUpdateSavedValues();
						}
					} catch (e) {
						showNotification(msg('parseError', 'Error: Could not parse server response.'), false);
					}
				}
			};

			xhr.send(formData);
		});
	}

	// =========================================================================
	// TEST MAIL (AJAX)
	// =========================================================================

	function setupTestMail() {
		var sendBtn = document.getElementById('sendTestEmail');
		var emailInput = document.getElementById('testEmailAddress');
		var resultsDiv = document.getElementById('testResults');

		if (!sendBtn || !emailInput || !resultsDiv) return;

		// Preserve the localized button label so it can be restored after
		// the request completes (previously a hardcoded English string
		// permanently replaced the translation).
		var idleLabel = sendBtn.innerHTML;
		var sendingLabel = msg('sending', 'Sending test email...');

		sendBtn.addEventListener('click', function() {
			var email = emailInput.value.trim();
			if (!email) {
				showNotification(msg('enterEmail', 'Please enter a valid email address.'), false);
				emailInput.focus();
				return;
			}

			resultsDiv.style.display = 'block';
			resultsDiv.innerHTML = '';
			var sendingStep = document.createElement('div');
			sendingStep.className = 'ms-test-step ms-test-step-info';
			sendingStep.textContent = '⌛ ' + sendingLabel;
			resultsDiv.appendChild(sendingStep);

			sendBtn.disabled = true;
			sendBtn.textContent = '⌛ ' + sendingLabel;

			var formData = new FormData();
			formData.append('testEmail', email);

			// CSRF token
			if (mailSettingsConfig.csrfToken) {
				formData.append('csrfToken', mailSettingsConfig.csrfToken);
			}

			var xhr = new XMLHttpRequest();
			xhr.open('POST', mailSettingsConfig.testMailUrl, true);

			xhr.onreadystatechange = function() {
				if (xhr.readyState === 4) {
					sendBtn.disabled = false;
					sendBtn.innerHTML = idleLabel;

					try {
						var data = JSON.parse(xhr.responseText);
						displayTestResults(data, resultsDiv);
					} catch (e) {
						resultsDiv.innerHTML = '';
						var errStep = document.createElement('div');
						errStep.className = 'ms-test-step ms-test-step-error';
						errStep.textContent = '❌ ' + msg('parseError', 'Error: Could not parse server response.');
						resultsDiv.appendChild(errStep);
					}
				}
			};

			xhr.send(formData);
		});
	}

	function displayTestResults(data, container) {
		var html = '';

		if (data.steps && data.steps.length) {
			data.steps.forEach(function(step) {
				var cls = 'ms-test-step-info';
				var icon = 'ℹ️';
				if (step.status === 'success') { cls = 'ms-test-step-success'; icon = '✓'; }
				if (step.status === 'error') { cls = 'ms-test-step-error'; icon = '✗'; }
				html += '<div class="ms-test-step ' + cls + '">' + icon + ' ' + escapeHtml(step.message) + '</div>';
			});
		}

		if (data.message) {
			var finalCls = data.success ? 'ms-test-step-success' : 'ms-test-step-error';
			var finalIcon = data.success ? '✅' : '❌';
			html += '<div class="ms-test-step ms-test-final ' + finalCls + '">' + finalIcon + ' ' + escapeHtml(data.message) + '</div>';
		}

		container.innerHTML = html;
	}

	// =========================================================================
	// NOTIFICATIONS
	// =========================================================================

	function showNotification(message, isSuccess) {
		var notif = document.getElementById('mailSettingsNotification');
		if (!notif) return;

		notif.textContent = message;
		notif.style.display = '';

		if (isSuccess) {
			notif.style.background = '#E8F5E9';
			notif.style.color = '#1B5E20';
			notif.style.border = '1px solid #A5D6A7';
		} else {
			notif.style.background = '#FFEBEE';
			notif.style.color = '#B71C1C';
			notif.style.border = '1px solid #EF9A9A';
		}

		if (isSuccess) {
			setTimeout(function() {
				notif.style.display = 'none';
			}, 5000);
		}
	}

	// =========================================================================
	// UTILS
	// =========================================================================

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

})();
