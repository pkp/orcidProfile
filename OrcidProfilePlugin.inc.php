<?php

/**
 * @file plugins/generic/orcidProfile/OrcidProfilePlugin.inc.php
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

define('ORCID_OAUTH_URL', 'https://orcid.org/oauth/');
define('ORCID_OAUTH_URL_SANDBOX', 'https://sandbox.orcid.org/oauth/');
define('ORCID_API_URL_PUBLIC', 'https://pub.orcid.org/');
define('ORCID_API_URL_PUBLIC_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('ORCID_API_URL_MEMBER', 'https://api.orcid.org/');
define('ORCID_API_URL_MEMBER_SANDBOX', 'https://api.sandbox.orcid.org/');

define('OAUTH_TOKEN_URL', 'oauth/token');
//define('ORCID_API_VERSION_URL', 'v2.0/');
define('ORCID_API_VERSION_URL', 'v1.2/');
define('ORCID_PROFILE_URL', 'orcid-profile');
define('ORCID_BIO_URL', 'orcid-bio');

class OrcidProfilePlugin extends GenericPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {
			// Register callback for Smarty filters; add CSS
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));

			// Insert ORCID callback
			HookRegistry::register('LoadHandler', array($this, 'setupCallbackHandler'));

			// Handle ORCID on user registration
			HookRegistry::register('registrationform::execute', array($this, 'collectUserOrcidId'));

			// Send emails to authors without ORCID id upon submission
			HookRegistry::register('Author::Form::Submit::AuthorSubmitStep3Form::Execute', array($this, 'collectAuthorOrcidId'));

			// Add ORCiD hash to author DAO
			HookRegistry::register('authordao::getAdditionalFieldNames', array($this, 'authorSubmitGetFieldNames'));
		}
		return $success;
	}

	/**
	 * Get page handler path for this plugin.
	 * @return string Path to plugin's page handler
	 */
	function getHandlerPath() {
		return $this->getPluginPath() . DIRECTORY_SEPARATOR . 'pages';
	}

	/**
	 * Hook callback: register pages for each sushi-lite method
	 * This URL is of the form: orcidapi/{$orcidrequest}
	 * @see PKPPageRouter::route()
	 */
	function setupCallbackHandler($hookName, $params) {
		$page = $params[0];
		if ($this->getEnabled() && $page == 'orcidapi') {
			$this->import('pages/OrcidHandler');
			define('HANDLER_CLASS', 'OrcidHandler');
			return true;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter to add data citation to submission
	 * summaries; add data citation to reading tools' suppfiles and metadata views.
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];
		$request = PKPApplication::getRequest();

		// Assign our private stylesheet, for front and back ends.
		$templateMgr->addStyleSheet(
			'orcidProfile',
			$request->getBaseUrl() . '/' . $this->getStyleSheet(),
			array(
				'contexts' => array('frontend', 'backend')
			)
		);

		switch ($template) {
			case 'user/register.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'registrationFilter'));
				break;
			case 'author/submit/step3.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'submitFilter'));
				break;
			case 'frontend/pages/userRegister.tpl': // OMP
				$templateMgr->register_outputfilter(array($this, 'registrationFilter'));
				break;
			case 'user/publicProfileForm.tpl':
				$templateMgr->register_outputfilter(array($this, 'profileFilter'));
				break;
			case 'frontend/pages/userLogin.tpl': // OMP
				$templateMgr->register_outputfilter(array(&$this, 'loginFilterOMP'));
				break;
			case 'controllers/grid/users/author/form/authorForm.tpl': // OMP
				$templateMgr->register_outputfilter(array(&$this, 'addCheckOrcidButton'));
				break;

		}
		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to OJS login form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function addCheckOrcidButton($output, &$templateMgr) {
        $sessionManager = SessionManager::getManager();
        $userSession = $sessionManager->getUserSession();

		$templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));

		if (preg_match('/<form.*id="editAuthor"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$templateMgr->assign(array(
				'targetOp' => 'login',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($context->getId(), 'orcidClientId'),
			));
			$newOutput = substr($output, 0, $offset);
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidLoginCheck.tpl');
			$newOutput .= substr($output, $offset);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('addCheckOrcidButton');
		return $output;
	}

	function smartyPluginUrl($params, &$smarty) {
		$request = PKPApplication::getRequest();
	    $dispatcher = $request->getDispatcher();
	    return $dispatcher->url($request, ROUTE_PAGE, null, 'plugins', 'generic', array_merge(array('plugin', $this->getName(), isset($params['path'])?$params['path']:array())));
	}

	/**
      * Search for author information in ORCiD registry.
      * @param $args array
      * @param $request PKPRequest
      */
     function orcidSearch($args, $request) {
         $plugin =& PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
         $templateMgr =& TemplateManager::getManager($request);

         $authorIndex = Request::getUserVar('authorIndex');
         $orcidButtonId = Request::getUserVar('orcidButtonId');
         $orcidInputId = Request::getUserVar('orcidInputId');
         $templateMgr->assign_by_ref('authorIndex', $authorIndex);
         $templateMgr->assign_by_ref('orcidButtonId', $orcidButtonId);
         $templateMgr->assign_by_ref('orcidInputId', $orcidInputId);

         switch (Request::getUserVar('targetOp')) {
             case 'form':
                 $templateMgr->display($plugin->getTemplatePath() . 'orcidProfileSearchForm.tpl');
                 break;
             case 'search':
                 $journal = Request::getJournal(); //
                 $name = $request->getUserVar('search-orcid-name');
                 $lastname = $request->getUserVar('search-orcid-lastname');
                 $email = $request->getUserVar('search-orcid-email');
                 $orcidSearchResults = array();
                 if (($name && $lastname) || $email) {
                     // Obtaining a search token
                     $curl = curl_init();
                     curl_setopt_array($curl, array(
                         CURLOPT_FAILONERROR => true,
                         CURLOPT_URL => $url = $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath').OAUTH_TOKEN_URL,
                         CURLOPT_RETURNTRANSFER => true,
                         CURLOPT_HTTPHEADER => array('Accept: application/json'),
                         CURLOPT_POST => true,
                         CURLOPT_POSTFIELDS => http_build_query(array(
                             'scope' => '/read-public',
                             'grant_type' => 'client_credentials',
                             'client_id' => $plugin->getSetting($journal->getId(), 'orcidClientId'),
                             'client_secret' => $plugin->getSetting($journal->getId(), 'orcidClientSecret')
                         ))
                     ));
                     $result = curl_exec($curl);
                     // Close request to clear up some resources
                     curl_close($curl);
                     if ($result) {
                         $response = json_decode($result, true);
                         $query = '?q=';
                         if ($name && $lastname && $email) {
                             $query .= '(given-names:' . $name . 'ANDfamily-name:' . $lastname . ')ORemail:' . $email;
                         } elseif ($name && $lastname) {
                             $query .= 'given-names:' . $name . 'ANDfamily-name:' . $lastname;
                         } else {
                             $query .= 'email:' . $email;
                         }

                         // Performing search
                         $curl = curl_init();
                         curl_setopt_array($curl, array(
                             CURLOPT_FAILONERROR    => true,
                             CURLOPT_RETURNTRANSFER => true,
                             CURLOPT_HTTPHEADER => array('Accept: application/json',
                                                         'Content-Type: application/orcidxml',
                                                         'Authorization: Bearer ' . $response['access_token']),
                             CURLOPT_POST => false,
                             CURLOPT_URL => $url = $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . 'search/' . ORCID_BIO_URL . '/' . $query,
                         ));
                         $result = curl_exec($curl);
                         if ($result) {
                             // Processing results
                             $response = json_decode($result, true);
                             if ($response['orcid-search-results']['num-found'] > 0) {
                                 foreach($response['orcid-search-results']['orcid-search-result'] as $resultItem) {
                                     $name = $resultItem['orcid-profile']['orcid-bio']['personal-details']['given-names']['value'];
                                     $lastname = $resultItem['orcid-profile']['orcid-bio']['personal-details']['family-name']['value'];
                                     $email = $resultItem['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value'];
                                     $orcidiD = $resultItem['orcid-profile']['orcid-identifier']['uri'];
                                     $orcidSearchResults[] = array('name' => $name,
                                                                   'lastname' => $lastname,
                                                                   'email' => $email,
                                                                   'orcidiD' => $orcidiD);
                                 }
                             }
                         }
                     }

                 }
                 $templateMgr->assign_by_ref('orcidSearchResults', $orcidSearchResults);
                 $templateMgr->display($plugin->getTemplatePath() . 'orcidProfileSearchResults.tpl');
                 break;
             default: assert(false);
         }
     }

	/**
	 * Return the OAUTH path (prod or sandbox) based on the current API configuration
	 * @return $string
	 */
	function getOauthPath() {
		$context = Request::getContext();
		$contextId = ($context == null) ? 0 : $context->getId();

		$apiPath =	$this->getSetting($contextId, 'orcidProfileAPIPath');
		if ($apiPath == ORCID_API_URL_PUBLIC || $apiPath == ORCID_API_URL_MEMBER) {
			return ORCID_OAUTH_URL;
		} else {
			return ORCID_OAUTH_URL_SANDBOX;
		}
	}

	/**
	 * Output filter adds ORCiD interaction to OJS login form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function loginFilter($output, &$templateMgr) {
        $sessionManager = SessionManager::getManager();
        $userSession = $sessionManager->getUserSession();
		if (preg_match('/<form.*id="signinForm"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) { // OMP: id="login" OJS: id="signinForm"
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$templateMgr->assign(array(
				'targetOp' => 'login',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($context->getId(), 'orcidClientId'),
			));
			$newOutput = substr($output, 0, $offset);
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidLogin.tpl');
			$newOutput .= substr($output, $offset);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('loginFilter');
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to OMP login form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function loginFilterOMP($output, &$templateMgr) {
        $sessionManager = SessionManager::getManager();
        $userSession = $sessionManager->getUserSession();
		if (preg_match('/<form.*id="login"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) { // OMP: id="login" OJS: id="signinForm"
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$templateMgr->assign(array(
				'targetOp' => 'login',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($context->getId(), 'orcidClientId'),
			));
			$newOutput = substr($output, 0, $offset);
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidLogin.tpl');
			$newOutput .= substr($output, $offset);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('loginFilterOMP');
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to registration form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function registrationFilter($output, &$templateMgr) {
		if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$contextId = ($context == null) ? 0 : $context->getId();

			$templateMgr->assign(array(
				'targetOp' => 'register',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
			));

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('registrationFilter');
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to user profile form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function profileFilter($output, &$templateMgr) {
		if (preg_match('/<label[^>]+for="orcid[^"]*"[^>]*>[^<]+<\/label>/', $output, $matches, PREG_OFFSET_CAPTURE) &&
			!(preg_match('/\$\(\'input\[name=orcid\]\'\)/', $output))) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$contextId = ($context == null) ? 0 : $context->getId();

			// Entering the registration without ORCiD; present the button.
			$templateMgr->assign(array(
				'targetOp' => 'profile',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
			));

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= '<script type="text/javascript">
					$(document).ready(function() {
					$(\'input[name=orcid]\').attr(\'readonly\', "true");
				});
			</script>';
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('profileFilter');
		return $output;
	}

	/**
	 * Collect the ORCID when registering a user.
	 * @param $hookName string
	 * @param $params array
	 * @return bool
	 */
	function collectUserOrcidId($hookName, $params) {
		$form = $params[0];
		$user =& $params[1];

		$form->readUserVars(array('orcid'));
		$user->setOrcid($form->getData('orcid'));
		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to the 3rd step submission form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return bool
	 */
	function collectAuthorOrcidId($hookName, $params) {
		$author =& $params[0];
		$formAuthor =& $params[1];

		// if author has no orcid id
		if (!$author->getData('orcid')){
			$mail = $this->getMailTemplate('ORCID_COLLECT_AUTHOR_ID');

			$orcidToken = md5(time());
			$author->setData('orcidToken', $orcidToken);

			$request = PKPApplication::getRequest();
			$context = $request->getContext();

			// This should only ever happen within a context, never site-wide.
			assert($context != null);
			$contextId = $context->getId();

			$articleDao = DAORegistry::getDAO('ArticleDAO');
			$article = $articleDao->getArticle($author->getSubmissionId());

			$mail->assignParams(array(
				'authorOrcidUrl' => $this->getOauthPath() . 'authorize?' . http_build_query(array(
					'client_id' => $this->getSetting($contextId, 'orcidClientId'),
					'response_type' => 'code',
					'scope' => '/authenticate',
					'redirect_uri' => Request::url(null, 'orcidapi', 'orcidVerify', null, array('orcidToken' => $orcidToken, 'articleId' => $author->getSubmissionId()))
				)),
				'authorName' => $author->getFullName(),
				'editorialContactSignature' => $context->getSetting('contactName'),
				'articleTitle' => $article->getLocalizedTitle(),
			));

			// Send to author
			$mail->addRecipient($author->getEmail(), $author->getFullName());

			// Send the mail.
			$mail->send($request);
		}
		return false;
	}

	/**
	 * Add the author hash storage to the author record
	 * @param $hookName string
	 * @param $params array
	 */
	function authorSubmitGetFieldNames($hookName, $params) {
		$fields =& $params[1];
		$fields[] = 'orcidToken';
		return false;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.orcidProfile.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.orcidProfile.description');
	}

	/**
	 * @copydoc PKPPlugin::getTemplatePath
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * @see PKPPlugin::getInstallEmailTemplatesFile()
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}

	/**
	 * @see PKPPlugin::getInstallEmailTemplateDataFile()
	 */
	function getInstallEmailTemplateDataFile() {
		return ($this->getPluginPath() . '/locale/{$installedLocale}/emailTemplates.xml');
	}

	/**
	 * Extend the {url ...} smarty to support this plugin.
	 */
	//function smartyPluginUrl($params, &$smarty) {
	//    $path = array($this->getCategory(), $this->getName());
	//    if (is_array($params['path'])) {
	//        $params['path'] = array_merge($path, $params['path']);
	//    } elseif (!empty($params['path'])) {
	//        $params['path'] = array_merge($path, array($params['path']));
	//    } else {
	//        $params['path'] = $path;
	//    }

	//    if (!empty($params['id'])) {
	//        $params['path'] = array_merge($params['path'], array($params['id']));
	//        unset($params['id']);
	//    }
	//    return $smarty->smartyUrl($params, $smarty);
	//}

	/**
	 * @see Plugin::getActions()
	 */
	function getActions($request, $actionArgs) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled()?array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url(
							$request,
							null,
							null,
							'manage',
							null,
							array(
								'verb' => 'settings',
								'plugin' => $this->getName(),
								'category' => 'generic'
							)
						),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			):array(),
			parent::getActions($request, $actionArgs)
		);
	}

	/**
	 * @see Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$context = $request->getContext();
				$contextId = ($context == null) ? 0 : $context->getId();

				$templateMgr = TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));
				$apiOptions = array(
					ORCID_API_URL_PUBLIC => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.public',
					ORCID_API_URL_PUBLIC_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.publicSandbox',
					ORCID_API_URL_MEMBER => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.member',
					ORCID_API_URL_MEMBER_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.memberSandbox'
				);

				$templateMgr->assign('orcidApiUrls', $apiOptions);

				$this->import('OrcidProfileSettingsForm');
				$form = new OrcidProfileSettingsForm($this, $contextId);
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * Return the location of the plugin's CSS file
	 * @return string
	 */
	function getStyleSheet() {
		return $this->getPluginPath() . '/css/orcidProfile.css';
	}

	/**
	 * Instantiate a MailTemplate
	 *
	 * @param $emailKey string
	 * @param $context Context
	 */
	function &getMailTemplate($emailKey, $context = null) {
		if (!isset($this->_mailTemplates[$emailKey])) {
			import('classes.mail.MailTemplate');
			$mailTemplate = new MailTemplate($emailKey, null, null, $context, true, true);
			$this->_mailTemplates[$emailKey] = $mailTemplate;
		}
		return $this->_mailTemplates[$emailKey];
	}

}
?>
