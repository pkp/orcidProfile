<?php

/**
 * @file OrcidProfileStatusForm.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfileStatusForm
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

class OrcidProfileStatusForm extends Form
{
    public const CONFIG_VARS = [
        'orcidProfileAPIPath' => 'string',
        'orcidClientId' => 'string',
        'orcidClientSecret' => 'string',
        'sendMailToAuthorsOnPublication' => 'bool',
        'logLevel' => 'string',
        'isSandBox' => 'bool'
    ];

    /** @var int $contextId */
    public $contextId;

    /** @var object $plugin */
    public $plugin;

    /** @var OrcidValidator */
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
        parent::__construct($plugin->getTemplateResource('statusForm.tpl'));
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
     * Fetch the form.
     *
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $contextId = $request->getContext()->getId();
        $clientId = $this->plugin->getSetting($contextId, 'orcidClientId');
        $clientSecret = $this->plugin->getSetting($contextId, 'orcidClientSecret');

        $templateMgr = TemplateManager::getManager($request);
        $aboutUrl = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'orcidapi', 'about', null);
        $templateMgr->assign([
            'globallyConfigured' => $this->plugin->isGloballyConfigured(),
            'orcidAboutUrl' => $aboutUrl,
            'pluginEnabled' => $this->plugin->getEnabled($contextId),
            'clientIdValid' => $this->validator->validateClientId($clientId),
            'clientSecretValid' => $this->validator->validateClientSecret($clientSecret),
        ]);
        return parent::fetch($request, $template, $display);
    }
}
