/**
 * plugins/generic/mailSettings/js/mailSettings.js
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Email Settings plugin - standalone page interactions
 */

(function() {
	'use strict';

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

				if (isCustom) {
					customPanel.classList.remove('ms-hidden');
				} else {
					customPanel.classList.add('ms-hidden');
				}

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
						? 'Using Custom Mail Settings'
						: 'Using OJS Default Configuration (config.inc.php)';
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
			smtpPanel.style.display = (this.value === 'smtp') ? '' : 'none';
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
			'gmail': '\u26A0\uFE0F Gmail requires an App Password (16 characters) instead of your regular password. Enable 2-Step Verification in your Google Account first, then generate an App Password at myaccount.google.com > Security > App Passwords.',
			'office365': '\u26A0\uFE0F Office 365 requires SMTP AUTH to be enabled for the mailbox. Your admin may need to enable this in the Exchange admin center. Multi-Factor Authentication (MFA) may require an App Password.',
			'yandex': '',
			'zoho': '',
			'custom': ''
		};

		presetCards.forEach(function(card) {
			card.addEventListener('click', function() {
				var preset = this.getAttribute('data-preset');
				var server = this.getAttribute('data-server');
				var port = this.getAttribute('data-port');
				var encryption = this.getAttribute('data-encryption');

				presetCards.forEach(function(c) {
					c.classList.remove('ms-preset-active');
				});
				this.classList.add('ms-preset-active');

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
						presetNote.innerHTML = escapeHtml(note);
						presetNote.style.display = 'block';
					} else {
						presetNote.style.display = 'none';
					}
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

		// Hide the toggle button initially — it only makes sense
		// when the user is typing a new password. The saved password
		// is never sent back from the server.
		toggleBtn.style.display = 'none';

		passwordField.addEventListener('input', function() {
			toggleBtn.style.display = this.value.length ? '' : 'none';
			if (!this.value.length) {
				this.type = 'password';
				toggleBtn.textContent = '\uD83D\uDC41 Show';
			}
		});

		toggleBtn.addEventListener('click', function() {
			if (passwordField.type === 'password') {
				passwordField.type = 'text';
				this.textContent = '\uD83D\uDD12 Hide';
			} else {
				passwordField.type = 'password';
				this.textContent = '\uD83D\uDC41 Show';
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
						showNotification('Error: Could not parse server response.', false);
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

		sendBtn.addEventListener('click', function() {
			var email = emailInput.value.trim();
			if (!email) {
				alert('Please enter an email address.');
				return;
			}

			resultsDiv.style.display = 'block';
			resultsDiv.innerHTML = '<div class="ms-test-step ms-test-step-info">\u231B Sending test email...</div>';
			sendBtn.disabled = true;
			sendBtn.innerHTML = '\u231B Sending...';

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
					sendBtn.innerHTML = '&#9993; Send Test Email';

					try {
						var data = JSON.parse(xhr.responseText);
						displayTestResults(data, resultsDiv);
					} catch (e) {
						resultsDiv.innerHTML = '<div class="ms-test-step ms-test-step-error">\u274C Error: Could not parse server response.</div>';
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
				var icon = '\u2139\uFE0F';
				if (step.status === 'success') { cls = 'ms-test-step-success'; icon = '\u2713'; }
				if (step.status === 'error') { cls = 'ms-test-step-error'; icon = '\u2717'; }
				html += '<div class="ms-test-step ' + cls + '">' + icon + ' ' + escapeHtml(step.message) + '</div>';
			});
		}

		if (data.message) {
			var finalCls = data.success ? 'ms-test-step-success' : 'ms-test-step-error';
			var finalIcon = data.success ? '\u2705' : '\u274C';
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
