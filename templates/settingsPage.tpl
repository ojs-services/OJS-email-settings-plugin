{**
 * plugins/generic/mailSettings/templates/settingsPage.tpl
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Email Settings plugin - standalone settings page
 *}

{extends file="layouts/backend.tpl"}

{block name="page"}
<link rel="stylesheet" href="{$pluginCssUrl}" />

<h1 class="app__pageHeading">
	{translate key="plugins.generic.mailSettings.displayName"}
</h1>

<div id="mailSettingsPage">

	{* ==================== NOTIFICATION AREA ==================== *}
	<div id="mailSettingsNotification" class="ms-notification"></div>

	{* ==================== STATUS BANNER ==================== *}
	<div id="mailSettingsStatus" class="ms-status-banner {if $configSource == 'custom'}ms-status-custom{else}ms-status-default{/if}">
		<span class="ms-status-icon" id="statusIcon">{if $configSource == 'custom'}&#9889;{else}&#128736;{/if}</span>
		<span>
			<strong>{translate key="plugins.generic.mailSettings.status.label"}:</strong>
			<span id="statusText">
				{if $configSource == 'custom'}
					{translate key="plugins.generic.mailSettings.status.custom"}
				{else}
					{translate key="plugins.generic.mailSettings.status.default"}
				{/if}
			</span>
		</span>
		{if $configSource == 'custom'}
		<div class="ms-stats-mini">
			<span class="ms-stat-item" title="{translate key="plugins.generic.mailSettings.stats.todayTip"}">
				<span class="ms-stat-num">{$mailStats.today|escape}</span>
				<span class="ms-stat-label">{translate key="plugins.generic.mailSettings.stats.today"}</span>
			</span>
			<span class="ms-stat-divider">|</span>
			<span class="ms-stat-item" title="{translate key="plugins.generic.mailSettings.stats.weekTip"}">
				<span class="ms-stat-num">{$mailStats.week|escape}</span>
				<span class="ms-stat-label">{translate key="plugins.generic.mailSettings.stats.week"}</span>
			</span>
			<span class="ms-stat-divider">|</span>
			<span class="ms-stat-item" title="{translate key="plugins.generic.mailSettings.stats.monthTip"}">
				<span class="ms-stat-num">{$mailStats.month|escape}</span>
				<span class="ms-stat-label">{translate key="plugins.generic.mailSettings.stats.month"}</span>
			</span>
		</div>
		<div class="ms-stats-note">{translate key="plugins.generic.mailSettings.stats.note"}</div>
		{/if}
	</div>

	<form id="mailSettingsForm" method="post" onsubmit="return false;">
		{csrf}

		{* ==================== CONFIG SOURCE SELECTION ==================== *}
		<div class="ms-card">
			<div class="ms-card-header">
				<span class="ms-card-header-icon">&#9881;</span>
				{translate key="plugins.generic.mailSettings.configSource.title"}
			</div>

			<div id="optionDefault" class="ms-source-option {if $configSource != 'custom'}ms-source-active{/if}">
				<label>
					<input type="radio" name="configSource" value="default" {if $configSource != 'custom'}checked="checked"{/if} />
					<div>
						<div class="ms-option-title">{translate key="plugins.generic.mailSettings.configSource.default"}</div>
						<div class="ms-option-desc">{translate key="plugins.generic.mailSettings.configSource.defaultDesc"}</div>
					</div>
				</label>
			</div>

			<div id="optionCustom" class="ms-source-option {if $configSource == 'custom'}ms-source-active{/if}">
				<label>
					<input type="radio" name="configSource" value="custom" {if $configSource == 'custom'}checked="checked"{/if} />
					<div>
						<div class="ms-option-title">{translate key="plugins.generic.mailSettings.configSource.custom"}</div>
						<div class="ms-option-desc">{translate key="plugins.generic.mailSettings.configSource.customDesc"}</div>
					</div>
				</label>
			</div>
		</div>

		{* ==================== CUSTOM MAIL SETTINGS ==================== *}
		<div id="customSettingsPanel" class="ms-custom-panel {if $configSource != 'custom'}ms-hidden{/if}">

			{* Mail Method *}
			<div class="ms-card">
				<div class="ms-card-header">
					<span class="ms-card-header-icon">&#128232;</span>
					{translate key="plugins.generic.mailSettings.mailMethod.title"}
				</div>
				<div class="ms-form-group">
					<select name="mailMethod" id="mailMethod" class="ms-select" style="max-width: 280px;">
						<option value="smtp" {if $mailMethod == 'smtp'}selected{/if}>{translate key="plugins.generic.mailSettings.mailMethod.smtp"}</option>
						<option value="phpmail" {if $mailMethod == 'phpmail'}selected{/if}>{translate key="plugins.generic.mailSettings.mailMethod.phpmail"}</option>
						<option value="sendmail" {if $mailMethod == 'sendmail'}selected{/if}>{translate key="plugins.generic.mailSettings.mailMethod.sendmail"}</option>
					</select>
				</div>
			</div>

			{* SMTP Settings Panel - Single Card *}
			<div id="smtpSettingsPanel" style="{if $mailMethod != 'smtp'}display:none;{/if}">
				<div class="ms-card">

					{* Service Presets *}
					<div class="ms-card-header">
						<span class="ms-card-header-icon">&#9889;</span>
						{translate key="plugins.generic.mailSettings.presets.title"}
					</div>
					<p style="color:#888; font-size:13px; margin:0 0 14px;">{translate key="plugins.generic.mailSettings.presets.description"}</p>
					<div class="ms-preset-grid">
						{foreach from=$presets key=presetKey item=preset}
						<div class="ms-preset-card mailPresetCard" data-preset="{$presetKey}"
							data-server="{$preset.server}" data-port="{$preset.port}" data-encryption="{$preset.encryption}">
							<span class="ms-preset-icon">{if $presetKey == 'gmail'}&#128231;{elseif $presetKey == 'office365'}&#128188;{elseif $presetKey == 'yandex'}&#128421;{elseif $presetKey == 'zoho'}&#128200;{else}&#9998;{/if}</span>
							<div class="ms-preset-name">{$preset.name}</div>
							{if $preset.server}
								<div class="ms-preset-info">{$preset.server}:{$preset.port}</div>
							{else}
								<div class="ms-preset-info">{translate key="plugins.generic.mailSettings.presets.manual"}</div>
							{/if}
						</div>
						{/foreach}
					</div>
					<div id="presetNote" class="ms-preset-note"></div>

					{* Divider *}
					<div class="ms-card-divider"></div>

					{* SMTP Server & Port *}
					<div class="ms-card-sub-header">
						<span class="ms-card-header-icon">&#128421;</span>
						{translate key="plugins.generic.mailSettings.smtp.title"}
					</div>

					<div class="ms-form-grid ms-form-grid-2-1">
						<div class="ms-form-group">
							<label class="ms-label" for="smtpServer">{translate key="plugins.generic.mailSettings.smtp.server"}</label>
							<input type="text" id="smtpServer" name="smtpServer" value="{$smtpServer|escape}" class="ms-input ms-input-mono" placeholder="smtp.example.com" />
						</div>
						<div class="ms-form-group">
							<label class="ms-label" for="smtpPort">{translate key="plugins.generic.mailSettings.smtp.port"}</label>
							<select name="smtpPort" id="smtpPort" class="ms-select">
								<option value="587" {if $smtpPort == '587'}selected{/if}>587 (TLS)</option>
								<option value="465" {if $smtpPort == '465'}selected{/if}>465 (SSL)</option>
								<option value="25" {if $smtpPort == '25'}selected{/if}>25</option>
								<option value="2525" {if $smtpPort == '2525'}selected{/if}>2525</option>
							</select>
						</div>
					</div>

					<div class="ms-form-grid ms-form-grid-2" style="margin-top: 18px;">
						<div class="ms-form-group">
							<label class="ms-label" for="smtpEncryption">{translate key="plugins.generic.mailSettings.smtp.encryption"}</label>
							<select name="smtpEncryption" id="smtpEncryption" class="ms-select">
								<option value="tls" {if $smtpEncryption == 'tls'}selected{/if}>TLS</option>
								<option value="ssl" {if $smtpEncryption == 'ssl'}selected{/if}>SSL</option>
								<option value="none" {if $smtpEncryption == 'none'}selected{/if}>{translate key="plugins.generic.mailSettings.smtp.noEncryption"}</option>
							</select>
						</div>
						<div class="ms-form-group">
							<label class="ms-label" for="smtpAuthType">{translate key="plugins.generic.mailSettings.smtp.authType"}</label>
							<select name="smtpAuthType" id="smtpAuthType" class="ms-select">
								<option value="" {if $smtpAuthType == ''}selected{/if}>{translate key="plugins.generic.mailSettings.smtp.authTypeAuto"}</option>
								<option value="LOGIN" {if $smtpAuthType == 'LOGIN'}selected{/if}>LOGIN</option>
								<option value="PLAIN" {if $smtpAuthType == 'PLAIN'}selected{/if}>PLAIN</option>
								<option value="CRAM-MD5" {if $smtpAuthType == 'CRAM-MD5'}selected{/if}>CRAM-MD5</option>
							</select>
						</div>
					</div>

					{* Divider *}
					<div class="ms-card-divider"></div>

					{* Credentials *}
					<div class="ms-card-sub-header">
						<span class="ms-card-header-icon">&#128272;</span>
						{translate key="plugins.generic.mailSettings.credentials.title"}
					</div>

					<div class="ms-form-grid ms-form-grid-2">
						<div class="ms-form-group">
							<label class="ms-label" for="smtpUsername">{translate key="plugins.generic.mailSettings.smtp.username"}</label>
							<input type="text" id="smtpUsername" name="smtpUsername" value="{$smtpUsername|escape}" class="ms-input ms-input-mono" placeholder="user@example.com" />
						</div>
						<div class="ms-form-group">
							<label class="ms-label" for="smtpPassword">{translate key="plugins.generic.mailSettings.smtp.password"}</label>
							<div class="ms-password-wrap">
								<input type="password" id="smtpPassword" name="smtpPassword" value="" class="ms-input ms-input-mono"
									placeholder="{if $hasPassword}{translate key="plugins.generic.mailSettings.smtp.passwordUnchanged"}{else}{translate key="plugins.generic.mailSettings.smtp.passwordEnter"}{/if}" />
								<button type="button" id="togglePassword" class="ms-password-toggle">
									{translate key="plugins.generic.mailSettings.smtp.showPassword"}
								</button>
							</div>
							{if $hasPassword}
								<span class="ms-password-status">{translate key="plugins.generic.mailSettings.smtp.passwordSet"}</span>
							{/if}
						</div>
					</div>

				</div>
			</div>

			{* ==================== ADVANCED SETTINGS ==================== *}
			<div class="ms-card">
				<details>
					<summary class="ms-advanced-toggle">
						<span class="ms-chevron">&#9654;</span>
						{translate key="plugins.generic.mailSettings.advanced.title"}
					</summary>
					<div class="ms-advanced-body">

						<div class="ms-form-group" style="margin-bottom: 18px;">
							<label class="ms-label" for="envelopeSender">{translate key="plugins.generic.mailSettings.advanced.envelopeSender"}</label>
							<input type="text" id="envelopeSender" name="envelopeSender" value="{$envelopeSender|escape}" class="ms-input ms-input-mono" style="max-width: 400px;" placeholder="noreply@example.com" />
							<div class="ms-hint">{translate key="plugins.generic.mailSettings.advanced.envelopeSenderDesc"}</div>
						</div>

						<label class="ms-checkbox-row">
							<input type="checkbox" name="forceEnvelopeSender" value="1" {if $forceEnvelopeSender}checked="checked"{/if} />
							<span>{translate key="plugins.generic.mailSettings.advanced.forceEnvelope"}</span>
						</label>

						<label class="ms-checkbox-row">
							<input type="checkbox" name="dmarcCompliant" value="1" {if $dmarcCompliant}checked="checked"{/if} />
							<span>{translate key="plugins.generic.mailSettings.advanced.dmarcCompliant"}</span>
						</label>

						<div class="ms-form-group" style="margin: 14px 0;">
							<label class="ms-label" for="dmarcDisplayName">{translate key="plugins.generic.mailSettings.advanced.dmarcDisplayName"}</label>
							<input type="text" id="dmarcDisplayName" name="dmarcDisplayName" value="{$dmarcDisplayName|escape}" class="ms-input" style="max-width: 400px;" />
							<div class="ms-hint">{translate key="plugins.generic.mailSettings.advanced.dmarcDisplayNameDesc"}</div>
						</div>

						<label class="ms-checkbox-row">
							<input type="checkbox" name="suppressCertCheck" value="1" {if $suppressCertCheck}checked="checked"{/if} />
							<span>{translate key="plugins.generic.mailSettings.advanced.suppressCert"} <span class="ms-danger-badge">{translate key="plugins.generic.mailSettings.advanced.notRecommended"}</span></span>
						</label>

					</div>
				</details>
			</div>

		</div> {* END customSettingsPanel *}

		{* ==================== SAVE BUTTON ==================== *}
		<div class="ms-save-area">
			<button type="button" id="saveSettingsBtn" class="ms-btn-save">
				&#128190; {translate key="common.save"}
			</button>
			<span id="saveSpinner" class="ms-save-spinner">&#9203; {translate key="common.saving"}</span>
		</div>

	</form>

	{* ==================== TEST EMAIL SECTION ==================== *}
	<div class="ms-test-section">
		<div class="ms-test-header">
			<span>&#128233;</span>
			{translate key="plugins.generic.mailSettings.test.title"}
		</div>
		<p class="ms-test-desc">{translate key="plugins.generic.mailSettings.test.description"}</p>
		<div class="ms-test-row">
			<div class="ms-form-group">
				<label class="ms-label" for="testEmailAddress">{translate key="plugins.generic.mailSettings.test.emailLabel"}</label>
				<input type="email" id="testEmailAddress" class="ms-input ms-input-mono" placeholder="test@example.com" />
			</div>
			<button type="button" id="sendTestEmail" class="ms-btn-test">
				&#9993; {translate key="plugins.generic.mailSettings.test.send"}
			</button>
		</div>

		<div id="testResults" class="ms-test-results"></div>
	</div>

	{* ==================== EMAIL DELIVERABILITY INFO ==================== *}
	<div class="ms-card ms-info-card">
		<div class="ms-card-header">
			<span class="ms-card-header-icon">&#128161;</span>
			{translate key="plugins.generic.mailSettings.deliverability.title"}
		</div>
		<div class="ms-info-content">
			<p>{translate key="plugins.generic.mailSettings.deliverability.intro"}</p>
			<div class="ms-info-items">
				<div class="ms-info-item">
					<span class="ms-info-badge">SPF</span>
					<span>{translate key="plugins.generic.mailSettings.deliverability.spf"}</span>
				</div>
				<div class="ms-info-item">
					<span class="ms-info-badge">DKIM</span>
					<span>{translate key="plugins.generic.mailSettings.deliverability.dkim"}</span>
				</div>
				<div class="ms-info-item">
					<span class="ms-info-badge">DMARC</span>
					<span>{translate key="plugins.generic.mailSettings.deliverability.dmarc"}</span>
				</div>
			</div>
			<p class="ms-info-note">{translate key="plugins.generic.mailSettings.deliverability.note"}</p>
		</div>
	</div>

</div>

<script src="{$pluginJsUrl}"></script>

{* Pass URLs and CSRF token to JavaScript *}
<script>
	var mailSettingsConfig = {ldelim}
		saveUrl: {$saveUrl|json_encode},
		testMailUrl: {$testMailUrl|json_encode},
		csrfToken: {$csrfToken|json_encode}
	{rdelim};
</script>
{/block}
