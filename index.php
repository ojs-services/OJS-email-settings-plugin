<?php

/**
 * @defgroup plugins_generic_mailSettings Mail Settings Plugin
 */

/**
 * @file plugins/generic/mailSettings/index.php
 *
 * Copyright (c) 2026 OJS Services (ojs-services.com)
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_mailSettings
 * @brief Wrapper for the Mail Settings plugin.
 */

require_once('MailSettingsPlugin.inc.php');

return new MailSettingsPlugin();
