<?php

/**
 * @file OrcidProfileSettingsForm.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfileSettingsForm
 *
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief Form for site admins to modify ORCID Profile plugin settings
 */

namespace APP\plugins\generic\orcidProfile\classes\form;

use APP\core\Application;
use APP\plugins\generic\orcidProfile\classes\OrcidValidator;
use APP\plugins\generic\orcidProfile\OrcidProfilePlugin;
use APP\template\TemplateManager;
use PKP\form\Form;

class OrcidProfileSettingsForm extends Form
{
    public const CONFIG_VARS = [
        'orcidProfileAPIPath' => 'string',
        'orcidClientId' => 'string',
        'orcidClientSecret' => 'string',
        'sendMailToAuthorsOnPublication' => 'bool',
        'logLevel' => 'string',
        'isSandBox' => 'bool',
        'country' => 'string',
        'city' => 'string'
    ];

    /** @var int $contextId */
    public $contextId;

    /** @var object $plugin */
    public $plugin;

    public $validator;

    /**
     * Constructor
     *
     * @param OrcidProfilePlugin $plugin
     * @param int $contextId
     */
    public function __construct($plugin, $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;
        $orcidValidator = new OrcidValidator($plugin);
        $this->validator = $orcidValidator;
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));

        if (!$this->plugin->isGloballyConfigured()) {
            $this->addCheck(new \PKP\form\validation\FormValidator($this, 'orcidProfileAPIPath', 'required', 'plugins.generic.orcidProfile.manager.settings.orcidAPIPathRequired'));
            $this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'orcidClientId', 'required', 'plugins.generic.orcidProfile.manager.settings.orcidClientId.error', function ($clientId) {
                return $this->validator->validateClientId($clientId);
            }));
            $this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'orcidClientSecret', 'required', 'plugins.generic.orcidProfile.manager.settings.orcidClientSecret.error', function ($clientSecret) {
                return $this->validator->validateClientSecret($clientSecret);
            }));
        }
    }

    /**
     * Initialize form data.
     */
    public function initData()
    {
        $contextId = $this->contextId;
        $plugin = & $this->plugin;
        $this->_data = [];
        foreach (self::CONFIG_VARS as $configVar => $type) {
            $this->_data[$configVar] = $plugin->getSetting($contextId, $configVar);
        }
    }

    /**
     * Assign form data to user-submitted data.
     */
    public function readInputData()
    {
        $this->readUserVars(array_keys(self::CONFIG_VARS));
    }

    /**
     * Fetch the form.
     *
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('globallyConfigured', $this->plugin->isGloballyConfigured());
        $templateMgr->assign('pluginName', $this->plugin->getName());
        $templateMgr->assign('applicationName', Application::get()->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = & $this->plugin;
        $contextId = $this->contextId;
        foreach (self::CONFIG_VARS as $configVar => $type) {
            if ($configVar === 'orcidProfileAPIPath') {
                $plugin->updateSetting($contextId, $configVar, trim($this->getData($configVar), "\"\';"), $type);
            } else {
                $plugin->updateSetting($contextId, $configVar, $this->getData($configVar), $type);
            }
        }
        if (strpos($this->getData('orcidProfileAPIPath'), 'sandbox.orcid.org') == true) {
            $plugin->updateSetting($contextId, 'isSandBox', true, 'bool');
        }

        parent::execute(...$functionArgs);
    }

    public function _checkPrerequisites()
    {
        $messages = [];

        $clientId = $this->getData('orcidClientId');
        if (!$this->validator->validateClientId($clientId)) {
            $messages[] = __('plugins.generic.orcidProfile.manager.settings.orcidClientId.error');
        }
        $clientSecret = $this->getData('orcidClientSecret');
        if (!$this->validator->validateClientSecret($clientSecret)) {
            $messages[] = __('plugins.generic.orcidProfile.manager.settings.orcidClientSecret.error');
        }
        return $messages;
    }
}
