<?php

/**
 * @file plugins/generic/orcidProfile/OrcidProfilePlugin.inc.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

define('ORCID_URL', 'https://orcid.org/');
define('ORCID_URL_SANDBOX', 'https://sandbox.orcid.org/');
define('ORCID_API_URL_PUBLIC', 'https://pub.orcid.org/');
define('ORCID_API_URL_PUBLIC_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('ORCID_API_URL_MEMBER', 'https://api.orcid.org/');
define('ORCID_API_URL_MEMBER_SANDBOX', 'https://api.sandbox.orcid.org/');
define('ORCID_API_VERSION_URL', 'v2.1/');
define('ORCID_API_SCOPE_PUBLIC', '/authenticate');
define('ORCID_API_SCOPE_MEMBER', '/activities/update');

define('OAUTH_TOKEN_URL', 'oauth/token');
define('ORCID_EMPLOYMENTS_URL', 'employments');
define('ORCID_PROFILE_URL', 'person');
define('ORCID_EMAIL_URL', 'email');
define('ORCID_WORK_URL', 'work');

class OrcidProfilePlugin extends GenericPlugin {
	const PUBID_TO_ORCID_EXT_ID = [ "doi" => "doi", "other::urn" => "urn"];
	const USERGROUP_TO_ORCID_ROLE = [ "Author" => "AUTHOR", "Translator" => "CHAIR_OR_TRANSLATOR"];

	private $submissionIdToBePublished;
	private $currentContextId;

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled($mainContextId)) {
			// Register callback for Smarty filters; add CSS
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
			// Add "Connect ORCID" button to PublicProfileForm
			HookRegistry::register('User::PublicProfile::AdditionalItems', array($this, 'handleUserPublicProfileDisplay'));
			// Display additional ORCID access information and checkbox to send e-mail to authors in the AuthorForm
			HookRegistry::register('authorform::display', array($this, 'handleFormDisplay'));
			// Send email to author, if the added checkbox was ticked
			HookRegistry::register('authorform::execute', array($this, 'handleAuthorFormExecute'));
			// Insert the OrcidHandler to handle ORCID redirects
			HookRegistry::register('LoadHandler', array($this, 'setupCallbackHandler'));

			// Handle ORCID on user registration
			HookRegistry::register('registrationform::execute', array($this, 'collectUserOrcidId'));

			// Send emails to authors without ORCID id upon submission
			HookRegistry::register('submissionsubmitstep3form::execute', array($this, 'handleSubmissionSubmitStep3FormExecute'));
			// Add more ORCiD fields to author DAO
			HookRegistry::register('authordao::getAdditionalFieldNames', array($this, 'handleAdditionalFieldNames'));
			// Add more ORCiD fields to UserDAO
			HookRegistry::register('userdao::getAdditionalFieldNames', array($this, 'handleAdditionalFieldNames'));
			// Send submission meta data upload to ORCID profiles on publication of an issue
			HookRegistry::register('IssueGridHandler::publishIssue', array($this, 'handlePublishIssue'));
			HookRegistry::register('issueentrypublicationmetadataform::execute', array($this, 'handleScheduleForPublication'));
			// Send emails to authors without authorised ORCID access on promoting a submission to production
			$contextId = ($mainContextId === null) ? $this->getCurrentContextId() : $mainContextId;
			if ($this->getSetting($contextId, 'sendMailToAuthorsOnPublication')) {
				HookRegistry::register('EditorAction::recordDecision', array($this, 'handleEditorAction'));
			}
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
	 * Load a setting for a specific journal or load it from the config.inc.php if it is specified there.
	 *
	 * @param  $contextId int The id of the journal from which the plugin settings should be loaded.
	 * @param  $name string   Name of the setting.
	 * @return mixed          The setting value, either from the database for this context
	 *                        or from the global configuration file.
	 */
	function getSetting($contextId, $name)
	{
		switch ($name) {
			case 'orcidProfileAPIPath':
				$config_value = Config::getVar('orcid','api_url');
				break;
			case 'orcidClientId':
				$config_value = Config::getVar('orcid','client_id');
				break;
			case 'orcidClientSecret':
				$config_value = Config::getVar('orcid','client_secret');
				break;
			default:
				return parent::getSetting($contextId, $name);
		}
		return $config_value ?: parent::getSetting($contextId, $name);
	}

	/**
	 * Check if there exist a valid orcid configuration section in the global config.inc.php of OJS.
	 * @return boolean True, if the config file has api_url, client_id and client_secret set in an [orcid] section
	 */
	function isGloballyConfigured() {
		$apiUrl = Config::getVar('orcid','api_url');
		$clientId = Config::getVar('orcid','client_id');
		$clientSecret = Config::getVar('orcid','client_secret');
		return isset($apiUrl) && trim($apiUrl) && isset($clientId) && trim($clientId) &&
			isset($clientSecret) && trim($clientSecret);
	}

	/**
	 * Hook callback to handle form display.
	 * Registers output filter for public user profile and author form.
	 *
	 * @see Form::display()
	 *
	 * @param $hookName string
	 * @param $args Form[]
	 *
	 * @return bool
	 */
	function handleFormDisplay($hookName, $args) {
		$request = PKPApplication::getRequest();
		$templateMgr = TemplateManager::getManager($request);
		switch ($hookName) {
			case 'authorform::display':
				$authorForm =& $args[0];
				$author = $authorForm->getAuthor();
				if ($author) {
					$authenticated = !empty($author->getData('orcidAccessToken'));
					$templateMgr->assign( array(
						'orcidAccessToken' => $author->getData('orcidAccessToken'),
						'orcidAccessScope' => $author->getData('orcidAccessScope'),
						'orcidAccessExpiresOn' => $author->getData('orcidAccessExpiresOn'),
						'orcidAccessDenied' => $author->getData('orcidAccessDenied'),
						'orcidAuthenticated' => $authenticated
					));
				}
				$templateMgr->registerFilter("output", array($this, 'authorFormFilter'));
				break;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter for user registration and article display.
	 *
	 * @see TemplateManager::display()
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
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
			case 'frontend/pages/userRegister.tpl':
				$templateMgr->registerFilter("output", array($this, 'registrationFilter'));
				break;
			case 'frontend/pages/article.tpl':
				//$templateMgr->assign('orcidIcon', $this->getIcon());
				$script = 'var orcidIconSvg = '. json_encode($this->getIcon()) .';';
				$article = $templateMgr->getTemplateVars('article');
				$authors = $article->getAuthors();
				foreach ($authors as $author) {
					if(!empty($author->getOrcid()) && !empty($author->getData('orcidAccessToken'))) {
						$script .= '$("a[href=\"'.$author->getOrcid().'\"]").html(orcidIconSvg + "' . $author->getOrcid() . '");';
					}
				}
				$templateMgr->addJavaScript('orcidIconDisplay', $script, ['inline' => true]);
				break;
		}
		return false;
	}

	/**
	 * Return the OAUTH path (prod or sandbox) based on the current API configuration
	 *
	 * @return string
	 */
	function getOauthPath() {
		return $this->getOrcidUrl() . 'oauth/';
	}

	/**
	 * Return the ORCID website url (prod or sandbox) based on the current API configuration
	 *
	 * @return string
	 */
	function getOrcidUrl() {
		if ($this->usingSandbox()) {
			return ORCID_URL_SANDBOX;
		} else {
			return ORCID_URL;
		}
	}

	/**
	 * Check if the plugin is configured to use the ORCID Sandbox API
	 *
	 * @return boolean true if using the ORCID Sandbox API, false otherwise
	 */
	function usingSandbox() {
		$context = Request::getContext();
		$contextId = ($context == null) ? 0 : $context->getId();

		$apiPath =	$this->getSetting($contextId, 'orcidProfileAPIPath');
		if ($apiPath == ORCID_API_URL_PUBLIC_SANDBOX || $apiPath == ORCID_API_URL_MEMBER_SANDBOX) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Return an ORCID OAuth authorization link with
	 *
	 * @param  $handlerMethod string containting a valid method of the OrcidHandler
	 * @param  $redirectParams Array associative array with additional request parameters for the redirect URL
	 */
	function buildOAuthUrl($handlerMethod, $redirectParams) {
		$request = PKPApplication::getRequest();
		$context = $request->getContext();
		// This should only ever happen within a context, never site-wide.
		assert($context != null);
		$contextId = $context->getId();

		if ( $this->isMemberApiEnabled($contextId) ) {
			$scope = ORCID_API_SCOPE_MEMBER;
		}
		else {
			$scope = ORCID_API_SCOPE_PUBLIC;
		}
		// We need to construct a page url, but the request is using the component router.
		// Use the Dispatcher to construct the url and set the page router.
		$redirectUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'orcidapi',
			$handlerMethod, null, $redirectParams);
		return $this->getOauthPath() . 'authorize?' . http_build_query(array(
				'client_id' => $this->getSetting($contextId, 'orcidClientId'),
				'response_type' => 'code',
				'scope' => $scope,
				'redirect_uri' => $redirectUrl));
	}

	/**
	 * Output filter adds ORCiD interaction to registration form.
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function registrationFilter($output, $templateMgr) {
		if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$contextId = ($context == null) ? 0 : $context->getId();
			$targetOp = 'register';
			$templateMgr->assign(array(
				'targetOp' => $targetOp,
				'orcidUrl' => $this->getOrcidUrl(),
				'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', array('targetOp' => $targetOp)),
				'orcidIcon' => $this->getIcon(),
			));

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplateResource('orcidProfile.tpl'));
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
			$templateMgr->unregisterFilter('output', array($this, 'registrationFilter'));
		}
		return $output;
	}

	/**
	 * Renders additional content for the PublicProfileForm.
	 *
	 * Called by @see lib/pkp/templates/user/publicProfileForm.tpl
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return bool
	 */
	function handleUserPublicProfileDisplay($hookName, $params) {
		$templateMgr =& $params[1];
		$output  =& $params[2];
		$context = Request::getContext();
		$user = Request::getUser();
		$contextId = ($context == null) ? 0 : $context->getId();
		$targetOp = 'profile';
		if ($user->getOrcid() && $user->getData('orcidSandbox')) {
			$templateMgr->assign('orcid', rtrim(ORCID_URL_SANDBOX, '/') . parse_url($user->getOrcid(), PHP_URL_PATH));
		}
		$templateMgr->assign(array(
			'targetOp' => $targetOp,
			'orcidUrl' => $this->getOrcidUrl(),
			'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', array('targetOp' => $targetOp)),
			'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
			'orcidIcon' => $this->getIcon(),
			'orcidAuthenticated' => !empty($user->getData('orcidAccessToken')),
		));

		$output = $templateMgr->fetch($this->getTemplateResource('orcidProfile.tpl'));
		return true;
	}

	/**
	 * Output filter adds ORCiD interaction to contributors metadata add/edit form.
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function authorFormFilter($output, $templateMgr) {
		if (preg_match('/<input[^>]+name="submissionId"[^>]*>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$templateMgr->assign('orcidIcon', $this->getIcon());
			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplateResource('authorFormOrcid.tpl'));
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
			$templateMgr->unregisterFilter('output', array($this, 'authorFormFilter'));
		}
		return $output;
	}

	/**
	 * handleAuthorFormexecute sends an e-mail to the author if a specific checkbox was ticked in the author form.
	 *
	 * @see AuthorForm::execute() The function calling the hook.
	 *
	 * @param $hookname string
	 * @param $args AuthorForm[]
	 */
	function handleAuthorFormExecute($hookname, $args) {
		$form =& $args[0];
		$form->readUserVars(array('requestOrcidAuthorization', 'deleteOrcid'));

		$requestAuthorization = $form->getData('requestOrcidAuthorization');
		$deleteOrcid = $form->getData('deleteOrcid');
		$author = $form->getAuthor();
		if ($author && $requestAuthorization) {
			$this->sendAuthorMail($author);
		}
		if ($author && $deleteOrcid) {
			$author->setOrcid(null);
			$this->removeOrcidAccessToken($author, false);
		}
	}

	/**
	 * Collect the ORCID when registering a user.
	 *
	 * @param $hookName string
	 * @param $params array
	 * @return bool
	 */
	function collectUserOrcidId($hookName, $params) {
		$form = $params[0];
		$user =& $params[1];

		$form->readUserVars(array('orcid'));
		$orcid = $form->getData('orcid');
		$username = $form->getData('username');
		$userDao = DAORegistry::getDAO('UserDAO');

		// On shutdown, persist the ORCID to the new user account. (https://github.com/pkp/pkp-lib/issues/4601)
		register_shutdown_function(function() use ($orcid, $username, $userDao) {
			$user = $userDao->getByUsername($username);
			$user->setOrcid($orcid);
			$userDao->updateObject($user);
		});

		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to the 3rd step submission form.
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return bool
	 */
	function handleSubmissionSubmitStep3FormExecute($hookName, $params) {
		$form = $params[0];
		// Have to use global Request access because request is not passed to hook
		$authors = $form->submission->getAuthors();
		$user = Request::getUser();
		//error_log("OrcidProfilePlugin: authors[0] = " . var_export($authors[0], true));
		//error_log("OrcidProfilePlugin: user = " . var_export($user, true));
		if($authors[0]->getOrcid() === $user->getOrcid()) {
			// if the author and user share the same ORCID id
			// copy the access token from the user
			error_log("OrcidProfilePlugin: user->orcidAccessToken = " . $user->getData('orcidAccessToken'));
			$authors[0]->setData('orcidAccessToken', $user->getData('orcidAccessToken'));
			$authors[0]->setData('orcidAccessScope', $user->getData('orcidAccessScope'));
			$authors[0]->setData('orcidRefreshToken', $user->getData('orcidRefreshToken'));
			$authors[0]->setData('orcidAccessExpiresOn', $user->getData('orcidAccessExpiresOn'));
			$authors[0]->setData('orcidSandbox', $user->getData('orcidSandbox'));
			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authorDao->updateObject($authors[0]);
			error_log("OrcidProfilePlugin: author = " . var_export($authors[0], true));
		}
		return false;
	}

	/**
	 * Add additional ORCID specific fields to the Author and User objects
	 *
	 * @param $hookName string
	 * @param $params array
	 *
	 * @return bool
	 */
	function handleAdditionalFieldNames($hookName, $params) {
		$fields =& $params[1];
		// the ORCID id was retrieved from the ORCID sandbox API
		$fields[] = 'orcidSandbox';
		$fields[] = 'orcidAccessToken';
		$fields[] = 'orcidAccessScope';
		$fields[] = 'orcidRefreshToken';
		$fields[] = 'orcidAccessExpiresOn';
		$fields[] = 'orcidAccessDenied';
		if ($hookName === 'authordao::getAdditionalFieldNames')  {
			// holds a one time hash string generated before sending the ORCID authorization e-mail
			$fields[] = 'orcidEmailToken';
			// holds the id of the added work entry in the corresponding ORCID profile for updates
			$fields[] = 'orcidWorkPutCode';
		}
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
	function smartyPluginUrl($params, $smarty) {
		$path = array($this->getCategory(), $this->getName());
		if (is_array($params['path'])) {
			$params['path'] = array_merge($path, $params['path']);
		} elseif (!empty($params['path'])) {
			$params['path'] = array_merge($path, array($params['path']));
		} else {
			$params['path'] = $path;
		}

		if (!empty($params['id'])) {
			$params['path'] = array_merge($params['path'], array($params['id']));
			unset($params['id']);
		}
		return $smarty->smartyUrl($params, $smarty);
	}

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
				$templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));
				$apiOptions = [
					ORCID_API_URL_PUBLIC => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.public',
					ORCID_API_URL_PUBLIC_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.publicSandbox',
					ORCID_API_URL_MEMBER => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.member',
					ORCID_API_URL_MEMBER_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.memberSandbox'
				];
				$templateMgr->assign('orcidApiUrls', $apiOptions);
				$templateMgr->assign('logLevelOptions', [
					'ERROR' => 'plugins.generic.orcidProfile.manager.settings.logLevel.error',
					'ALL' => 'plugins.generic.orcidProfile.manager.settings.logLevel.all'
				]);
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
	 *
	 * @return string
	 */
	function getStyleSheet() {
		return $this->getPluginPath() . '/css/orcidProfile.css';
	}

	/**
	 * Return a string of the ORCiD SVG icon
	 *
	 * @return string
	 */
	function getIcon() {
		$path = Core::getBaseDir() . '/' . $this->getPluginPath() . '/templates/images/orcid.svg';
		return file_exists($path) ? file_get_contents($path) : '';
	}

	/**
	 * Instantiate a MailTemplate
	 *
	 * @param string $emailKey
	 * @param Context $context
	 *
	 * @return MailTemplate
	 */
	function getMailTemplate($emailKey, $context = null) {
		import('lib.pkp.classes.mail.MailTemplate');
		return new MailTemplate($emailKey, null, $context, false);
	}

	/**
	 * Send mail with ORCID authorization link to the e-mail address of the supplied Author object.
	 *
	 * @param Author $author
	 * @param bool $updateAuthor If true update the author fields in the database.
	 *    Use this only if not called from a function, which does this anyway.
	 */
	public function sendAuthorMail($author, $updateAuthor = false)
	{
		$request = PKPApplication::getRequest();
		$context = $request->getContext();
		// This should only ever happen within a context, never site-wide.
		assert($context != null);
		$contextId = $context->getId();
		if ( $this->isMemberApiEnabled($contextId) ) {
			$mailTemplate = 'ORCID_REQUEST_AUTHOR_AUTHORIZATION';
		}
		else {
			$mailTemplate = 'ORCID_COLLECT_AUTHOR_ID';
		}
		$mail = $this->getMailTemplate($mailTemplate, $context);
		$emailToken = md5(microtime().$author->getEmail());
		$author->setData('orcidEmailToken', $emailToken);
		$articleDao = DAORegistry::getDAO('ArticleDAO');
		$article = $articleDao->getById($author->getSubmissionId());
		$oauthUrl = $this->buildOAuthUrl('orcidVerify', array('token' => $emailToken, 'articleId' => $author->getSubmissionId()));
		$aboutUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'orcidapi', 'about', null);
		// Set From to primary journal contact
		$mail->setFrom($context->getSetting('contactEmail'), $context->getSetting('contactName'));
		// Send to author
		$mail->setRecipients(array(array('name' => $author->getFullName(), 'email' => $author->getEmail())));
		// Send the mail with parameters
		$mail->sendWithParams(array(
			'orcidAboutUrl' => $aboutUrl,
			'authorOrcidUrl' => $oauthUrl,
			'authorName' => $author->getFullName(),
			'articleTitle' => $article->getLocalizedTitle(),
		));
		if ($updateAuthor) {
			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authorDao->updateLocaleFields($author);
		}
	}

	/**
	 * handlePublishIssue sends all submissions for which the authors hava an ORCID and access token
	 * to ORCID. This hook will be called on publication of a new issue.
	 *
	 * @see
	 *
	 * @param $hookName string
	 * @param $args Issue[] Issue object that will be published
	 *
	 **/
	public function handlePublishIssue($hookName, $args) {
		$issue =& $args[0];
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$publishedArticles = $publishedArticleDao->getPublishedArticles($issue->getId());
		$request = PKPApplication::getRequest();
		$journal = $request->getContext();

		foreach ($publishedArticles as $publishedArticle) {
			$articleId = $publishedArticle->getId();
			$this->sendSubmissionToOrcid($articleId, $request, $issue);
		}
	}

	/**
	* handleScheduleForPublication is a hook called during the "Schedule for publication" step
	* from the production stage of a submission. It registers another hook, because at the time of calling this hook,
	* the issue
	*
	* @param $hookName string The name the hook was registered as.
	* @param $args array Hook arguments, $form, $request, &$returner
	*
	* @see IssueEntryPublicationMetadataForm::execute() The function calling the hook.
	*/
	public function handleScheduleForPublication($hookName, $args) {
		$form = $args[0];
		$submissionId = $form->getSubmission()->getId();
		$this->submissionIdToBePublished = $submissionId;
		HookRegistry::register('ArticleSearchIndex::articleChangesFinished',
			[$this, 'handleScheduleForPublicationFinished']);
	}

	/**
	* handleScheduleForPublicationFinished is a hook registered by handleScheduleForPublication and sends the
	* submission data to orcid. The hook will be called at the end of IssueEntryPublicationMetadataForm::execute()
	*
	* @param $hookName string The name the hook was registered as.
	* @param $args array Hook arguments
	*
	* @see IssueEntryPublicationMetadataForm::execute() The function calling the hook.
	*/
	public function handleScheduleForPublicationFinished($hookName, $args) {
		if ( $this->submissionIdToBePublished ) {
			$request = PKPApplication::getRequest();
			$this->sendSubmissionToOrcid($this->submissionIdToBePublished, $request);
			$this->submissionIdToBePublished = null;
		}
	}

	/**
	* handleEditorAction handles promoting a submission to production.
	*
	* @param $hookName string Name the hook was registered with
	* @param $args array Hook arguments, &$submission, &$editorDecision, &$result, &$recommendation.
	*
	* @see EditorAction::recordDecision() The function calling the hook.
	*/
	public function handleEditorAction($hookName, $args) {
		$submission =& $args[0];
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$authors = $authorDao->getBySubmissionId($submission->getId());
		foreach ($authors as $author) {
			$orcidAccessExpiresOn = Carbon\Carbon::parse($author->getData('orcidAccessExpiresOn'));
			if ( $author->getData('orcidAccessToken') == null || $orcidAccessExpiresOn->isPast()) {
				$this->sendAuthorMail($author, true);
			}
		}
	}

	/**
	 * Check if the submission with the supplied id is actually published.
	 * @param  int     $submissionId Id of the submission/article to check.
	 * @return boolean True, if the article is part of a published issue. False otherwise.
	 */
	public function isSubmissionPublished($submissionId) {
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$article = $publishedArticleDao->getByArticleId($submissionId);
		if ( $article === null ) {
			return false;
		}
		$issue = DAORegistry::getDAO('IssueDAO')->getById($article->getIssueId());
		if ( $issue === null || !$issue->getPublished()) {
			return false;
		}
		return true;
	}

	/**
	 * sendSubmissionToOrcid posts JSON consisting of submission, journal and issue meta data
	 * to ORCID profiles of submission authors.
	 *
	 * @see https://github.com/ORCID/ORCID-Source/tree/master/orcid-model/src/main/resources/record_2.1
	 * for documentation and examples of the ORCID JSON format.
	 *
	 * @param $submissionId integer Id of the article for which the data will be sent to ORCID
	 * @return void
	 *
	 **/
	public function sendSubmissionToOrcid($submissionId, $request, $issue = null) {
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$article = $publishedArticleDao->getByArticleId($submissionId);
		if ( $article === null ) {
			$this->logError("No PublishedArticle found for id $submissionId");
			return false;
		}
		if ( $issue === null ) {
			$issue = DAORegistry::getDAO('IssueDAO')->getById($article->getIssueId());
		}
		if ( $issue === null || !$issue->getPublished()) {
			// Issue not yet published, do not send
			return false;
		}
		$journal = $request->getContext();
		$this->currentContextId = $journal->getId();
		if (!$this->isMemberApiEnabled($journal->getId())) {
			// Sending to ORCID only works with the member API
			return false;
		}
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$authors = $authorDao->getBySubmissionId($submissionId);
		// Collect valid ORCID ids and access tokens from submission contributors
		$authorsWithOrcid = [];
		foreach ($authors as $author) {
			if ($author->getOrcid() && $author->getData('orcidAccessToken') ) {
				$orcidAccessExpiresOn = Carbon\Carbon::parse($author->getData('orcidAccessExpiresOn'));
				if ($orcidAccessExpiresOn->isFuture()) {
					# Extract only the ORCID from the stored ORCID uri
					$orcid = basename(parse_url($author->getOrcid(), PHP_URL_PATH));
					$authorsWithOrcid[$orcid] = $author;
				}
				else {
					$this->logError("Token expired on $orcidAccessExpiresOn for author ". $author->getId() .
									", deleting orcidAccessToken!");
					$this->removeOrcidAccessToken($author);
				}
			}
		}
		if ( empty($authorsWithOrcid) ) {
			$this->logInfo('No contributor with ORICD id or valid access token for submission ' . $submissionId);
			return false;
		}
		$orcidWork = $this->buildOrcidWork($article, $journal, $authors, $issue, $request);
		$this::logInfo("Request body (without put-code): " . json_encode($orcidWork));
		$requestsSuccess = [];
		foreach ($authorsWithOrcid as $orcid => $author) {
			$url = $this->getSetting($journal->getId(), 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . $orcid . '/'
					. ORCID_WORK_URL;
			$method = "POST";

			if ( $putCode = $author->getData('orcidWorkPutCode')) {
				// Submission has already been sent to ORCID. Use PUT to update meta data
				$url .= '/' . $putCode;
				$method = "PUT";
				$orcidWork['put-code'] = $putCode;
			}
			else {
				// Remove put-code from body because the work has not yet been sent
				unset($orcidWork['put-code']);
			}
			$orcidWorkJson = json_encode($orcidWork);
			$header = [
				'Content-Type: application/vnd.orcid+json',
				'Content-Length: ' . strlen($orcidWorkJson),
				'Accept: application/json',
				'Authorization: Bearer ' . $author->getData('orcidAccessToken')
			];
			$this->logInfo("$method $url");
			$this->logInfo("Header: " . var_export($header, true));
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_POSTFIELDS => $orcidWorkJson,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $header
			]);
			// Use proxy if configured
			if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
				curl_setopt($ch, CURLOPT_PROXY, $httpProxyHost);
				curl_setopt($ch, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));
				if ($username = Config::getVar('proxy', 'username')) {
					curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
				}
			}
			$responseHeaders = [];
			// Needed to correctly process response headers.
			// This function is called by curl for each received line of the header.
			// Code from StackOverflow answer here: https://stackoverflow.com/a/41135574/8938233
			// Thanks to StackOverflow user Geoffrey.
			curl_setopt($ch, CURLOPT_HEADERFUNCTION,
				function($curl, $header) use (&$responseHeaders)
				{
					$len = strlen($header);
					$header = explode(':', $header, 2);
					if (count($header) < 2) {
						// ignore invalid headers
						return $len;
					}

					$name = strtolower(trim($header[0]));
					if (!array_key_exists($name, $responseHeaders)) {
						$responseHeaders[$name] = [trim($header[1])];
					}
					else {
						$responseHeaders[$name][] = trim($header[1]);
					}
					return $len;
				}
			);
			$result = curl_exec($ch);
			if (curl_error($ch)) {
				$this->logError('Unable to post to ORCID API, curl error: ' . curl_error($ch));
				curl_close($ch);
				return false;
			}
			$httpstatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			$this->logInfo("Response status: $httpstatus");
			switch ($httpstatus) {
				case 200:
					// Work updated
					$this->logInfo("Work updated in profile, putCode: $putCode");
					$requestsSuccess[$orcid] = true;
					break;
				case 201:
					$location = $responseHeaders['location'][0];
					// Extract the ORCID work put code for updates/deletion.
					$putCode = intval(basename(parse_url($location, PHP_URL_PATH)));
					$this->logInfo("Work added to profile, putCode: $putCode");
					$author->setData('orcidWorkPutCode', $putCode);
					$authorDao->updateLocaleFields($author);
					$requestsSuccess[$orcid] = true;
					break;
				case 401:
					// invalid access token, token was revoked
					$error = json_decode($result);
					if ($error->error === 'invalid_token') {
						$this->logError("$error->error_description, deleting orcidAccessToken from author");
						$this->removeOrcidAccessToken($author);
					}
					$requestsSuccess[$orcid] = false;
					break;
				case 404:
					// a work has been deleted from a ORCID record. putCode is no longer valid.
					if ($method === 'PUT') {
						$this->logError("Work deleted from ORCID record, deleting putCode form author");
						$author->setData('orcidWorkPutCode', null);
						$authorDao->updateLocaleFields($author);
						$requestsSuccess[$orcid] = false;
					}
					else {
						$this->logError("Unexpected status $httpstatus response, body: $result");
						$requestsSuccess[$orcid] = false;
					}
					break;
				case 409:
					$this->logError('Work already added to profile, response body: '. $result);
					$requestsSuccess[$orcid] = false;
					break;
				default:
					$this->logError("Unexpected status $httpstatus response, body: $result");
					$requestsSuccess[$orcid] = false;
			}
		}
		if (array_product($requestsSuccess) ) {
			return true;
		}
		else {
			return $requestsSuccess;
		}
	}

	/**
	 * Build an associative array with submission meta data, which can be encoded to a valid ORCID work JSON structure.
	 *
	 * @see https://github.com/ORCID/ORCID-Source/blob/master/orcid-model/src/main/resources/record_2.1/samples/write_sample/bulk-work-2.1.json
	 *  Example of valid ORCID JSON for adding works to an ORCID record.
	 * @param  Article $article  extract data from this Article
	 * @param  Journal $journal  Journal (context) object the Article is part of
	 * @param  Author[] $authors Array of Author objects, the contributors of the article
	 * @param  Issue $issue      Issue the Article is part of
	 * @param  Request $request  the current request
	 * @return array             an associative array with article meta data corresponding to ORCID work JSON structure
	 */
	public function buildOrcidWork($article, $journal, $authors, $issue, $request) {
		$articleLocale = $article->getLocale();
		$titles = $article->getTitle($articleLocale);
		$citationPlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
		$articleUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'article', 'view', $article->getBestArticleId());
		$orcidWork = [
			'title' => [
				'title' => [
					'value' => $article->getLocalizedTitle($articleLocale)
				],
			],
			'journal-title' => [
				'value' => $journal->getLocalizedName($articleLocale)
			],
			'short-description' => trim(strip_tags($article->getLocalizedData('abstract', $articleLocale))),
			'type' => 'JOURNAL_ARTICLE',
			'external-ids' => [ 'external-id' => $this->buildOrcidExternalIds($article, $journal, $issue, $articleUrl)],
			'publication-date' => $this->buildOrcidPublicationDate($issue),
			'url' => $articleUrl,
			'language-code' => substr($articleLocale, 0, 2),
			'contributors' => [ 'contributor' => $this->buildOrcidContributors($authors, $journal->getId()) ]
		];
		if ($citationPlugin) {
			$bibtexCitation = trim(strip_tags($citationPlugin->getCitation($request, $article, 'bibtex')));
			$orcidWork['citation'] = [
				'citation-type' => 'BIBTEX',
				'citation-value' => $bibtexCitation
			];
		}
		if ($article->getSubtitle($articleLocale)) {
			$orcidWork['title']['subtitle'] = [ 'value' => $article->getSubtitle($articleLocale)];
		}
		if ($articleLocale !== 'en_US' && $article->getTitle('en_US')) {
			$orcidWork['title']['translated-title'] = [
				'value' => $article->getTitle('en_US'),
				'language-code' => 'en'
			];
		}
		return $orcidWork;
	}


	/**
	 * Parse issue year and publication date and use the older on of the two as
	 * the publication date of the ORCID work.
	 *
	 * @return array Associative array with year, month and day or only year
	 */
	private function buildOrcidPublicationDate($issue) {
		$issuePublicationDate = Carbon\Carbon::parse($issue->getDatePublished());
		return [
			'year' => [ 'value' => $issuePublicationDate->format("Y")],
			'month' => [ 'value' => $issuePublicationDate->format("m")],
			'day' => [ 'value' => $issuePublicationDate->format("d")]
		];
	}

	/**
	 * Build the external identifiers ORCID JSON structure from article, journal and issue meta data.
	 *
	 * @see  https://pub.orcid.org/v2.0/identifiers Table of valid ORCID identifier types.
	 *
	 * @param  Article $article The Article object for which the external identifiers should be build.
	 * @param  Journal $journal Journal the Article is part of.
	 * @param  Issue   $issue   The Issue object the Article object belongs to.
	 * @return array            An associative array corresponding to ORCID external-id JSON.
	 */
	private function buildOrcidExternalIds($article, $journal, $issue, $articleUrl) {
		$externalIds = array();
		$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $article->getContextId());
		// Add doi, urn, etc. for article
		$articleHasStoredPubId = false;
		if (is_array($pubIdPlugins)) {
			foreach ($pubIdPlugins as $plugin) {
				if (!$plugin->getEnabled()) {
					continue;
				}
				$pubIdType = $plugin->getPubIdType();
				# Add article ids
				$pubId = $article->getStoredPubId($pubIdType);
				if ($pubId)	{
					$externalIds[] = [
						'external-id-type' => self::PUBID_TO_ORCID_EXT_ID[$pubIdType],
						'external-id-value' => $pubId,
						'external-id-url' => [ 'value' => $plugin->getResolvingURL($article->getContextId(), $pubId) ],
						'external-id-relationship' => 'SELF'
					];
					$articleHasStoredPubId = true;
				}
				# Add issue ids if they exist
				$pubId = $issue->getStoredPubId($pubIdType);
				if ($pubId) {
					$externalIds[] = [
					'external-id-type' => self::PUBID_TO_ORCID_EXT_ID[$pubIdType],
					'external-id-value' => $pubId,
					'external-id-url' => [ 'value' => $plugin->getResolvingURL($article->getContextId(), $pubId) ],
					'external-id-relationship' => 'PART_OF'
					];
				}
			}
		}
		else {
			error_log("OrcidProfilePlugin::buildOrcidExternalIds: No pubId plugins could be loaded");
		}
		if ( !$articleHasStoredPubId ) {
			// No pubidplugins available or article does not have any stored pubid
			// Use URL as an external-id
			$externalIds[] = [
				'external-id-type' => 'uri',
				'external-id-value' => $articleUrl,
				'external-id-relationship' => 'SELF'
			];
		}
		// Add journal online ISSN
		// TODO What about print ISSN?
		if ($journal->getData('onlineIssn')) {
			$externalIds[] = [
				'external-id-type' => 'issn',
				'external-id-value' => $journal->getData('onlineIssn'),
				'external-id-relationship' => 'PART_OF'
			];
		}

		return $externalIds;
	}

	/**
	 * Build associative array fitting for ORCID contributor mentions in an
	 * ORCID work from the supplied Authors array.
	 *
	 * @param  $authors Author[] Array of Author objects
	 * @param  $contextId int    Id of the context the Author objects belong to
	 * @return array[]           Array of associative arrays,
	 *                           one for each contributor
	 */
	private function buildOrcidContributors($authors, $contextId) {
		$contributors = [];
		$first = true;
		foreach ($authors as $author) {
			// TODO Check if e-mail address should be added
			$contributor = [
				'credit-name' => $author->getFullName(),
				'contributor-attributes' => [
					'contributor-sequence' => $first ? 'FIRST' : 'ADDITIONAL'
				]
			];
			$role = self::USERGROUP_TO_ORCID_ROLE[$author->getUserGroup()->getName('en_US')];
			if ($role) {
				$contributor['contributor-attributes']['contributor-role'] = $role;
			}
			if ($author->getOrcid()) {
				$orcid = basename(parse_url($author->getOrcid(), PHP_URL_PATH));
				if( $author->getData('orcidSandbox') ) {
					$uri = 'https://sandbox.orcid.org/' . $orcid;
					$host = 'sandbox.orcid.org';
				}
				else {
					$uri = $author->getOrcid();
					$host = 'orcid.org';
				}
				$contributor['contributor-orcid'] = [
					'uri' => $uri,
					'path' => $orcid,
					'host' => $host
				];
			}
			$first = false;
			$contributors[] = $contributor;
		}
		return $contributors;
	}

	/**
	 * Remove all data fields, which belong to an ORCID access token from the
	 * given Author object. Also updates fields in the db.
	 *
	 * @param  $author Author object with ORCID access token
	 * @return void
	 */
	public function removeOrcidAccessToken($author, $saveAuthor=true) {
		$author->setData('orcidAccessToken', null);
		$author->setData('orcidAccessScope', null);
		$author->setData('orcidRefreshToken', null);
		$author->setData('orcidAccessExpiresOn', null);
		$author->setData('orcidSandbox', null);
		if ($save) {
			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authorDao->updateLocaleFields($author);
		}
	}

	/**
	 * @return string Path to a custom ORCID log file.
	 */
	public static function logFilePath() {
		return Config::getVar('files', 'files_dir') . '/orcid.log';
	}

	/**
	 * Write error message to log.
	 *
	 * @param  $message string Message to write
	 * @return void
	 */
	public function logError($message) {
		self::writeLog($message, 'ERROR');
	}

	/**
	 * Write info message to log.
	 *
	 * @param  $message string Message to write
	 * @return void
	 */
	public function logInfo($message) {
		if ($this->getSetting($this->currentContextId, 'logLevel') === 'ERROR') {
			return;
		}
		else {
			self::writeLog($message, 'INFO');
		}
	}

	/**
	 * Write a message with specified level to log
	 *
	 * @param  $message string Message to write
	 * @param  $level   string Error level to add to message
	 * @return void
	 */
	private static function writeLog($message, $level) {
		$fineStamp = date('Y-m-d H:i:s') . substr(microtime(), 1, 4);
		error_log("$fineStamp $level $message\n", 3, self::logFilePath());
	}

	/**
	 * Set the current id of the context (atm only considered for logging settings).
	 *
	 * @param $contextId int the Id of the currently active context (journal)
	 */
	public function setCurrentContextId($contextId) {
		$this->currentContextId = $contextId;
	}

	/**
	 * @return bool True if the ORCID Member API has been selected in this context.
	 */
	public function isMemberApiEnabled($contextId) {
		$apiUrl = $this->getSetting($contextId, 'orcidProfileAPIPath');
		if ( $apiUrl === ORCID_API_URL_MEMBER || $apiUrl === ORCID_API_URL_MEMBER_SANDBOX ) {
			return true;
		}
		else {
			return false;
		}
	}
}

