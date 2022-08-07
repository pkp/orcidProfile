<?php

/**
 * @file OrcidProfilePlugin.inc.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

use GuzzleHttp\Exception\ClientException;

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.orcidProfile.classes.OrcidValidator');

define('ORCID_URL', 'https://orcid.org/');
define('ORCID_URL_SANDBOX', 'https://sandbox.orcid.org/');
define('ORCID_API_URL_PUBLIC', 'https://pub.orcid.org/');
define('ORCID_API_URL_PUBLIC_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('ORCID_API_URL_MEMBER', 'https://api.orcid.org/');
define('ORCID_API_URL_MEMBER_SANDBOX', 'https://api.sandbox.orcid.org/');
define('ORCID_API_VERSION_URL', 'v3.0/');
define('ORCID_API_SCOPE_PUBLIC', '/authenticate');
define('ORCID_API_SCOPE_MEMBER', '/activities/update');

define('OAUTH_TOKEN_URL', 'oauth/token');
define('ORCID_EMPLOYMENTS_URL', 'employments');
define('ORCID_PROFILE_URL', 'person');
define('ORCID_EMAIL_URL', 'email');
define('ORCID_WORK_URL', 'work');
define('ORCID_REVIEW_URL', 'peer-review');

class OrcidProfilePlugin extends GenericPlugin {

	const PUBID_TO_ORCID_EXT_ID = ["doi" => "doi", "other::urn" => "urn"];
	const USER_GROUP_TO_ORCID_ROLE = ["Author" => "AUTHOR", "Translator" => "CHAIR_OR_TRANSLATOR", "Journal manager" => "AUTHOR"];

	private $submissionIdToBePublished;
	private $currentContextId;

	/**
	 * @copydoc Plugin::register()
	 * @param $category
	 * @param $path
	 * @param null $mainContextId
	 * @return bool
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;

		$this->currentContextId = ($mainContextId === null) ? $this->getCurrentContextId() : $mainContextId;

		HookRegistry::register('ArticleHandler::view', array(&$this, 'submissionView'));
		HookRegistry::register('PreprintHandler::view', array(&$this, 'submissionView'));

		// Insert the OrcidHandler to handle ORCID redirects
		HookRegistry::register('LoadHandler', array($this, 'setupCallbackHandler'));

		// Register callback for Smarty filters; add CSS
		HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));

		// Add "Connect ORCID" button to PublicProfileForm
		HookRegistry::register('User::PublicProfile::AdditionalItems', array($this, 'handleUserPublicProfileDisplay'));

		// Display additional ORCID access information and checkbox to send e-mail to authors in the AuthorForm
		HookRegistry::register('authorform::display', array($this, 'handleFormDisplay'));

		// Send email to author, if the added checkbox was ticked
		HookRegistry::register('authorform::execute', array($this, 'handleAuthorFormExecute'));

		// Handle ORCID on user registration
		HookRegistry::register('registrationform::execute', array($this, 'collectUserOrcidId'));

		// Send emails to authors without ORCID id upon submission
		HookRegistry::register('submissionsubmitstep3form::execute', array($this, 'handleSubmissionSubmitStep3FormExecute'));

		HookRegistry::register('authordao::getAdditionalFieldNames', array($this, 'handleAdditionalFieldNames'));

		// Add more ORCiD fields to UserDAO
		HookRegistry::register('userdao::getAdditionalFieldNames', array($this, 'handleAdditionalFieldNames'));

		// Send emails to authors without authorised ORCID access on promoting a submission to copy editing. Not included in OPS.
		if ($this->getSetting($this->currentContextId, 'sendMailToAuthorsOnPublication')) {
			HookRegistry::register('EditorAction::recordDecision', array($this, 'handleEditorAction'));
		}

		HookRegistry::register('Publication::publish', array($this, 'handlePublicationStatusChange'));

		HookRegistry::register('ThankReviewerForm::thankReviewer', array($this, 'handleThankReviewer'));

		// Add more ORCiD fields to author Schema
		HookRegistry::register('Schema::get::author', function ($hookName, $args) {
			$schema = $args[0];

			$schema->properties->orcidSandbox = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidAccessToken = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidAccessScope = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidRefreshToken = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidAccessExpiresOn = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidAccessDenied = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidEmailToken = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
			$schema->properties->orcidWorkPutCode = (object)[
				'type' => 'string',
				'apiSummary' => true,
				'validation' => ['nullable']
			];
		});
		return $success;
	}

	/**
	 * @param $hookName
	 * @param $args
	 */
	function handleThankReviewer($hookName, $args) {
		$request = PKPApplication::get()->getRequest();
		$context = $request->getContext();
		$newPublication =& $args[0];
		if ($this->getSetting($context->getId(), 'country') && $this->getSetting($context->getId(), 'city')) {
			$this->publishReviewerWorkToOrcid($newPublication, $request);
		}
	}

	/**
	 * Load a setting for a specific journal or load it from the config.inc.php if it is specified there.
	 *
	 * @param  $contextId int The id of the journal from which the plugin settings should be loaded.
	 * @param  $name string   Name of the setting.
	 * @return mixed          The setting value, either from the database for this context
	 *                        or from the global configuration file.
	 */
	function getSetting($contextId, $name) {
		switch ($name) {
			case 'orcidProfileAPIPath':
				$config_value = Config::getVar('orcid', 'api_url');
				break;
			case 'orcidClientId':
				$config_value = Config::getVar('orcid', 'client_id');
				break;
			case 'orcidClientSecret':
				$config_value = Config::getVar('orcid', 'client_secret');
				break;
			case 'country':
				$config_value = Config::getVar('orcid', 'country');
				break;
			case 'city':
				$config_value = Config::getVar('orcid', 'city');
				break;
			default:
				return parent::getSetting($contextId, $name);
		}

		return $config_value ?: parent::getSetting($contextId, $name);
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
	 * Check if there exist a valid orcid configuration section in the global config.inc.php of OJS.
	 * @return boolean True, if the config file has api_url, client_id and client_secret set in an [orcid] section
	 */
	function isGloballyConfigured() {
		$apiUrl = Config::getVar('orcid', 'api_url');
		$clientId = Config::getVar('orcid', 'client_id');
		$clientSecret = Config::getVar('orcid', 'client_secret');
		return isset($apiUrl) && trim($apiUrl) && isset($clientId) && trim($clientId) &&
			isset($clientSecret) && trim($clientSecret);
	}

	/**
	 * Hook callback to handle form display.
	 * Registers output filter for public user profile and author form.
	 *
	 * @param $hookName string
	 * @param $args Form[]
	 *
	 * @return bool
	 * @see Form::display()
	 *
	 */
	function handleFormDisplay($hookName, $args) {
		$request = PKPApplication::get()->getRequest();
		$templateMgr = TemplateManager::getManager($request);
		switch ($hookName) {
			case 'authorform::display':
				$authorForm =& $args[0];
				$author = $authorForm->getAuthor();
				if ($author) {
					$authenticated = !empty($author->getData('orcidAccessToken'));
					$templateMgr->assign(
						array(
							'orcidAccessToken' => $author->getData('orcidAccessToken'),
							'orcidAccessScope' => $author->getData('orcidAccessScope'),
							'orcidAccessExpiresOn' => $author->getData('orcidAccessExpiresOn'),
							'orcidAccessDenied' => $author->getData('orcidAccessDenied'),
							'orcidAuthenticated' => $authenticated
						)
					);
				}

				$templateMgr->registerFilter("output", array($this, 'authorFormFilter'));
				break;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter for user registration and article display.
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 * @see TemplateManager::display()
	 *
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];
		$request = PKPApplication::get()->getRequest();

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
		}
		return false;
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
	 * Output filter adds ORCiD interaction to registration form.
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function registrationFilter($output, $templateMgr) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if ($context != null) {
			if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
				$match = $matches[0][0];
				$offset = $matches[0][1];
				$targetOp = 'register';
				$templateMgr->assign(array(
					'targetOp' => $targetOp,
					'orcidUrl' => $this->getOrcidUrl(),
					'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', array('targetOp' => $targetOp)),
					'orcidIcon' => $this->getIcon(),
				));

				$newOutput = substr($output, 0, $offset + strlen($match));
				$newOutput .= $templateMgr->fetch($this->getTemplateResource('orcidProfile.tpl'));
				$newOutput .= substr($output, $offset + strlen($match));
				$output = $newOutput;
				$templateMgr->unregisterFilter('output', array($this, 'registrationFilter'));
			}
		}
		return $output;
	}

	/**
	 * Return the ORCID website url (prod or sandbox) based on the current API configuration
	 *
	 * @return string
	 */
	function getOrcidUrl() {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextId = ($context == null) ? 0 : $context->getId();

		$apiPath = $this->getSetting($contextId, 'orcidProfileAPIPath');
		if ($apiPath == ORCID_API_URL_PUBLIC || $apiPath == ORCID_API_URL_MEMBER) {
			return ORCID_URL;
		} else {
			return ORCID_URL_SANDBOX;
		}
	}

	/**
	 * Return an ORCID OAuth authorization link with
	 *
	 * @param  $handlerMethod string containing a valid method of the OrcidHandler
	 * @param  $redirectParams Array associative array with additional request parameters for the redirect URL
	 */
	function buildOAuthUrl($handlerMethod, $redirectParams) {
		$request = PKPApplication::get()->getRequest();
		$context = $request->getContext();
		// This should only ever happen within a context, never site-wide.
		assert($context != null);
		$contextId = $context->getId();

		if ($this->isMemberApiEnabled($contextId)) {
			$scope = ORCID_API_SCOPE_MEMBER;
		} else {
			$scope = ORCID_API_SCOPE_PUBLIC;
		}
		// We need to construct a page url, but the request is using the component router.
		// Use the Dispatcher to construct the url and set the page router.
		$redirectUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'orcidapi',
			$handlerMethod, null, $redirectParams);

		return $this->getOauthPath() . 'authorize?' . http_build_query(
				array(
					'client_id' => $this->getSetting($contextId, 'orcidClientId'),
					'response_type' => 'code',
					'scope' => $scope,
					'redirect_uri' => $redirectUrl)
			);
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
	 * @return bool True if the ORCID Member API has been selected in this context.
	 */
	public function isMemberApiEnabled($contextId) {
		$apiUrl = $this->getSetting($contextId, 'orcidProfileAPIPath');
		if ($apiUrl === ORCID_API_URL_MEMBER || $apiUrl === ORCID_API_URL_MEMBER_SANDBOX) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Return the OAUTH path (prod or sandbox) based on the current API configuration
	 *
	 * @return string
	 */
	function getOauthPath() {
		return $this->getOrcidUrl() . 'oauth/';
	}

	public function isSandbox() {

		$apiUrl = $this->getSetting($this->getCurrentContextId(), 'orcidProfileAPIPath');
		return ($apiUrl == ORCID_API_URL_MEMBER_SANDBOX);

	}

	/**
	 * Renders additional content for the PublicProfileForm.
	 *
	 * Called by
	 * @param $hookName
	 * @param $params
	 * @return bool @see lib/pkp/templates/user/publicProfileForm.tpl
	 */
	function handleUserPublicProfileDisplay($hookName, $params) {
		$templateMgr =& $params[1];
		$output =& $params[2];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		$contextId = ($context == null) ? 0 : $context->getId();
		$targetOp = 'profile';
		$templateMgr->assign(
			array(
				'targetOp' => $targetOp,
				'orcidUrl' => $this->getOrcidUrl(),
				'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', array('targetOp' => $targetOp)),
				'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
				'orcidIcon' => $this->getIcon(),
				'orcidAuthenticated' => !empty($user->getData('orcidAccessToken')),
			)
		);

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
			$newOutput = substr($output, 0, $offset + strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplateResource('authorFormOrcid.tpl'));
			$newOutput .= substr($output, $offset + strlen($match));
			$output = $newOutput;
			$templateMgr->unregisterFilter('output', array($this, 'authorFormFilter'));
		}
		return $output;
	}

	/**
	 * handleAuthorFormexecute sends an e-mail to the author if a specific checkbox was ticked in the author form.
	 *
	 * @param $hookname string
	 * @param $args AuthorForm[]
	 * @see AuthorForm::execute() The function calling the hook.
	 *
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
	 * Send mail with ORCID authorization link to the e-mail address of the supplied Author object.
	 *
	 * @param Author $author
	 * @param bool $updateAuthor If true update the author fields in the database.
	 *    Use this only if not called from a function, which does this anyway.
	 */
	public function sendAuthorMail($author, $updateAuthor = false) {
		$request = PKPApplication::get()->getRequest();
		$context = $request->getContext();

		// This should only ever happen within a context, never site-wide.
		if ($context != null) {

			$contextId = $context->getId();

			if ($this->isMemberApiEnabled($contextId)) {
				$mailTemplate = 'ORCID_REQUEST_AUTHOR_AUTHORIZATION';
			} else {
				$mailTemplate = 'ORCID_COLLECT_AUTHOR_ID';
			}

			$mail = $this->getMailTemplate($mailTemplate, $context);
			$emailToken = md5(microtime() . $author->getEmail());

			$author->setData('orcidEmailToken', $emailToken);

			$publicationDao = DAORegistry::getDAO('PublicationDAO');
			/** @var PublicationDAO $publicationDao */
			$publication = $publicationDao->getById($author->getData('publicationId'));

			$oauthUrl = $this->buildOAuthUrl('orcidVerify', array('token' => $emailToken, 'state' => $publication->getId()));
			$aboutUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'orcidapi', 'about', null);

			// Set From to primary journal contact
			$mail->setFrom($context->getData('contactEmail'), $context->getData('contactName'));

			// Send to author
			$mail->setRecipients(array(array('name' => $author->getFullName(), 'email' => $author->getEmail())));

			// Send the mail with parameters
			$mail->sendWithParams([
				'orcidAboutUrl' => $aboutUrl,
				'authorOrcidUrl' => $oauthUrl,
				'authorName' => htmlspecialchars($author->getFullName()),
				'articleTitle' => htmlspecialchars($publication->getLocalizedTitle()), // Backwards compatibility only
				'submissionTitle' => htmlspecialchars($publication->getLocalizedTitle()),
			]);

			if ($updateAuthor) {
				$authorDao = DAORegistry::getDAO('AuthorDAO');
				$authorDao->updateObject($author);
			}
		}
	}

	/**
	 * Remove all data fields, which belong to an ORCID access token from the
	 * given Author object. Also updates fields in the db.
	 *
	 * @param  $author Author object with ORCID access token
	 * @return void
	 */
	public function removeOrcidAccessToken($author, $saveAuthor = true) {
		$author->setData('orcidAccessToken', null);
		$author->setData('orcidAccessScope', null);
		$author->setData('orcidRefreshToken', null);
		$author->setData('orcidAccessExpiresOn', null);
		$author->setData('orcidSandbox', null);

		if ($saveAuthor) {
			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authorDao->updateObject($author);
		}
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
	 * Collect the ORCID when registering a user.
	 *
	 * @param $hookName string
	 * @param $params array
	 * @return bool
	 */
	function collectUserOrcidId($hookName, $params) {
		$form = $params[0];
		$user = $form->user;

		$form->readUserVars(array('orcid'));
		$user->setOrcid($form->getData('orcid'));
		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to the 3rd step submission form.
	 *
	 * @param $hookName
	 * @param $params
	 * @return bool
	 */
	function handleSubmissionSubmitStep3FormExecute($hookName, $params) {
		$form = $params[0];
		// Have to use global Request access because request is not passed to hook
		$publicationDao = DAORegistry::getDAO('PublicationDAO');
		/* @var $publicationDao PublicationDAO */
		$publication = $publicationDao->getById($form->submission->getData('currentPublicationId'));
		$authors = $publication->getData('authors');

		$request = Application::get()->getRequest();
		$user = $request->getUser();
		//error_log("OrcidProfilePlugin: authors[0] = " . var_export($authors[0], true));
		//error_log("OrcidProfilePlugin: user = " . var_export($user, true));
		if ($authors[0]->getOrcid() === $user->getOrcid()) {
			// if the author and user share the same ORCID id
			// copy the access token from the user
			//error_log("OrcidProfilePlugin: user->orcidAccessToken = " . $user->getData('orcidAccessToken'));
			$authors[0]->setData('orcidAccessToken', $user->getData('orcidAccessToken'));
			$authors[0]->setData('orcidAccessScope', $user->getData('orcidAccessScope'));
			$authors[0]->setData('orcidRefreshToken', $user->getData('orcidRefreshToken'));
			$authors[0]->setData('orcidAccessExpiresOn', $user->getData('orcidAccessExpiresOn'));
			$authors[0]->setData('orcidSandbox', $user->getData('orcidSandbox'));

			$authorDao = DAORegistry::getDAO('AuthorDAO');
			/* @var $authorDao AuthorDAO */
			$authorDao->updateObject($authors[0]);

			//error_log("OrcidProfilePlugin: author = " . var_export($authors[0], true));
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
		$fields[] = 'orcidSandbox';
		$fields[] = 'orcidAccessToken';
		$fields[] = 'orcidAccessScope';
		$fields[] = 'orcidRefreshToken';
		$fields[] = 'orcidAccessExpiresOn';
		$fields[] = 'orcidAccessDenied';

		return false;
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

	function submissionView($hookName, $args) {
		$request = $args[0];
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array("orcidIcon" => $this->getIcon()));
	}

	/**
	 * @see Plugin::getActions()
	 */
	function getActions($request, $actionArgs) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			array(
				new LinkAction(
					'settings',
					new AjaxModal($router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')), $this->getDisplayName()),
					__('manager.plugins.settings'),
					null
				),
				new LinkAction(
					'status',
					new AjaxModal($router->url($request, null, null, 'manage', null, array('verb' => 'status', 'plugin' => $this->getName(), 'category' => 'generic')), $this->getDisplayName()),
					__('common.status'),
					null
				),
			),
			parent::getActions($request, $actionArgs)
		);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.orcidProfile.displayName');
	}

	function setEnabled($enabled) {
		$contextId = $this->getCurrentContextId();
		$request = Application::get()->getRequest();
		$validator = new OrcidValidator($this);

		if ($this->isSitePlugin()) {
			$contextId = 0;
		}
		if ($request->getUserVar('save') == 1) {
			$clientId = $request->getUserVar('orcidClientId');
			$clientSecret = $request->getUserVar('orcidClientSecret');
		} else {
			$clientId = $this->getSetting($contextId, 'orcidClientId');
			$clientSecret = $this->getSetting($contextId, 'orcidClientSecret');

		}

		if (!$validator->validateClientSecret($clientSecret) or !$validator->validateClientId($clientId)) {
			$enabled = false;

		}
		$this->updateSetting($contextId, 'enabled', $enabled, 'bool');
	}

	function manage($args, $request) {
		$context = $request->getContext();
		$contextId = ($context == null) ? 0 : $context->getId();

		switch ($request->getUserVar('verb')) {
			case 'settings':
				$templateMgr = TemplateManager::getManager();
				$templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));
				$apiOptions = [
					ORCID_API_URL_PUBLIC => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.public',
					ORCID_API_URL_PUBLIC_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.publicSandbox',
					ORCID_API_URL_MEMBER => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.member',
					ORCID_API_URL_MEMBER_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.memberSandbox'
				];
				$templateMgr->assign('orcidApiUrls', $apiOptions);

				$isoCodes = new \Sokil\IsoCodes\IsoCodesFactory();
				$countries = array();
				foreach ($isoCodes->getCountries() as $country) {
					$countries[$country->getAlpha2()] = $country->getLocalName();
				}
				asort($countries);
				$templateMgr->assign('countries', $countries);
				$templateMgr->assign('logLevelOptions', [
					'ERROR' => 'plugins.generic.orcidProfile.manager.settings.logLevel.error',
					'ALL' => 'plugins.generic.orcidProfile.manager.settings.logLevel.all'
				]);

				$this->import('classes.form.OrcidProfileSettingsForm');
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
			case 'status':
				$this->import('classes.form.OrcidProfileStatusForm');
				$form = new OrcidProfileStatusForm($this, $contextId);
				$form->initData();
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * handlePublishIssue sends all submissions for which the authors hava an ORCID and access token
	 * to ORCID. This hook will be called on publication of a new issue.
	 *
	 * @param $hookName string
	 * @param $args Issue[] Issue object that will be published
	 *
	 **@see
	 *
	 */
	public function handlePublicationStatusChange($hookName, $args) {
		$newPublication =& $args[0];
		/** @var $newPublication Publication */
		$publication =& $args[1];
		/** @var $publication Publication */
		$submission =& $args[2];
		/** @var $submission Submission */

		$request = PKPApplication::get()->getRequest();

		switch ($newPublication->getData('status')) {
			case STATUS_PUBLISHED:
			case STATUS_SCHEDULED:
				$this->publishAuthorWorkToOrcid($newPublication, $request);
				break;
		}
	}

	/**
	 * @param Submission $submission
	 * @param Request $request
	 * @return false|void
	 */
	public function publishReviewerWorkToOrcid(Submission $submission, Request $request) {
		$context = $request->getContext();
		$requestVars  = $request->getUserVars();
		import('lib.pkp.classes.submission.reviewAssignment.ReviewAssignmentDAO');
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
		$reviewAssignmentId = $requestVars['reviewAssignmentId'];
		if (isset($reviewAssignmentId)) {
		$review = $reviewAssignmentDao->getById($reviewAssignmentId);
		$reviewer = Services::get('user')->get($review->getData('reviewerId'));
			if ($reviewer->getOrcid() && $reviewer->getData('orcidAccessToken')) {
				$orcidAccessExpiresOn = Carbon\Carbon::parse($reviewer->getData('orcidAccessExpiresOn'));
				if ($orcidAccessExpiresOn->isFuture()) {
					# Extract only the ORCID from the stored ORCID uri
					$orcid = basename(parse_url($reviewer->getOrcid(), PHP_URL_PATH));
					$orcidReview = $this->buildOrcidReview($submission, $review, $request);
					$uri = $this->getSetting($context->getId(), 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . $orcid . '/' . ORCID_REVIEW_URL;
					$method = "POST";
					if ($putCode = $reviewer->getData('orcidReviewPutCode')) {
						$uri .= '/' . $putCode;
						$method = "PUT";
						$orcidReview['put-code'] = $putCode;
					}
					$headers = [
						'Content-Type' => ' application/vnd.orcid+json; qs=4',
						'Accept' => 'application/json',
						'Authorization' => 'Bearer '. $reviewer->getData("orcidAccessToken")
					];
					$httpClient = Application::get()->getHttpClient();
					$requestsSuccess = [];

					try {
						$response = $httpClient->request(
							$method,
							$uri,
							[
								'headers' => $headers,
								'json' => $orcidReview,
							]
						);
					} catch (ClientException $exception) {
						$reason = $exception->getResponse()->getBody(false);
						$this->logInfo("Publication fail: $reason");
						return new JSONMessage(false);
					}
					$httpstatus = $response->getStatusCode();
					$this->logInfo("Response status: $httpstatus");
					$responseHeaders = $response->getHeaders();
					switch ($httpstatus) {
						case 200:
							$this->logInfo("Review updated in profile, putCode: $putCode");
							$requestsSuccess[$orcid] = true;
							break;
						case 201:
							$location = $responseHeaders['Location'][0];
							// Extract the ORCID work put code for updates/deletion.
							$putCode = basename(parse_url($location, PHP_URL_PATH));
							$reviewer->setData('orcidReviewPutCode', $putCode);
							$authorDao = DAORegistry::getDAO('AuthorDAO');
							$authorDao->updateObject($reviewer);
							$requestsSuccess[$orcid] = true;
							$this->logInfo("Review added to profile, putCode: $putCode");
							break;
						default:
							$this->logError("Unexpected status $httpstatus response, body: $responseHeaders");
							$requestsSuccess[$orcid] = false;
					}
				}

				}

		}
	}

	/**
	 * sendSubmissionToOrcid posts JSON consisting of submission, journal and issue meta data
	 * to ORCID profiles of submission authors.
	 *
	 * @see https://github.com/ORCID/ORCID-Source/tree/master/orcid-model/src/main/resources/record_2.1
	 * for documentation and examples of the ORCID JSON format.
	 *
	 * @param $publication Publication for which the data will be sent to ORCID
	 * @param $submission Submission for which the data will be sent to ORCID
	 * @return bool||bool[]
	 *
	 **/
	public function publishAuthorWorkToOrcid($publication, $request) {
		$context = $request->getContext();
		$contextId = $context->getId();
		$publicationId = $publication->getId();
		$submissionId = $publication->getData('submissionId');

		if (!$this->isMemberApiEnabled($contextId)) {
			// Sending to ORCID only works with the member API
			return false;
		}

		$issueId = $publication->getData('issueId');
		if (isset($issueId)) {
			$issue = Services::get('issue')->get($issueId);
		}

		$authorDao = DAORegistry::getDAO('AuthorDAO');
		/** @var $authorDao AuthorDAO */
		$authors = $authorDao->getByPublicationId($publicationId);

		// Collect valid ORCID ids and access tokens from submission contributors
		$authorsWithOrcid = [];
		foreach ($authors as $author) {
			if ($author->getOrcid() && $author->getData('orcidAccessToken')) {
				$orcidAccessExpiresOn = Carbon\Carbon::parse($author->getData('orcidAccessExpiresOn'));
				if ($orcidAccessExpiresOn->isFuture()) {
					# Extract only the ORCID from the stored ORCID uri
					$orcid = basename(parse_url($author->getOrcid(), PHP_URL_PATH));
					$authorsWithOrcid[$orcid] = $author;
				} else {
					$this->logError("Token expired on $orcidAccessExpiresOn for author " . $author->getId() . ", deleting orcidAccessToken!");
					$this->removeOrcidAccessToken($author);
				}
			}
		}

		if (empty($authorsWithOrcid)) {
			$this->logInfo('No contributor with ORICD id or valid access token for submission ' . $submissionId);
			return false;
		}

		$orcidWork = $this->buildOrcidWork($publication, $context, $authors, $request, $issue);
		$this::logInfo("Request body (without put-code): " . json_encode($orcidWork));

		$requestsSuccess = [];
		foreach ($authorsWithOrcid as $orcid => $author) {
			$uri = $this->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . $orcid . '/' . ORCID_WORK_URL;
			$method = "POST";

			if ($putCode = $author->getData('orcidWorkPutCode')) {
				// Submission has already been sent to ORCID. Use PUT to update meta data
				$uri .= '/' . $putCode;
				$method = "PUT";
				$orcidWork['put-code'] = $putCode;
			} else {
				// Remove put-code from body because the work has not yet been sent
				unset($orcidWork['put-code']);
			}


			$headers = [
				'Content-type: application/vnd.orcid+json',
				'Accept' => 'application/json',
				'Authorization' => 'Bearer ' . $author->getData('orcidAccessToken')
			];

			$this->logInfo("$method $uri");
			$this->logInfo("Header: " . var_export($headers, true));

			$httpClient = Application::get()->getHttpClient();
			try {
				$response = $httpClient->request(
					$method,
					$uri,
					[
						'headers' => $headers,
						'json' => $orcidWork,
					]
				);
			} catch (ClientException $exception) {
				$reason = $exception->getResponse()->getBody(false);
				$this->logInfo("Publication fail: $reason");
				return new JSONMessage(false);
			}
			$httpstatus = $response->getStatusCode();
			$this->logInfo("Response status: $httpstatus");
			$responseHeaders = $response->getHeaders();

			switch ($httpstatus) {
				case 200:
					// Work updated
					$this->logInfo("Work updated in profile, putCode: $putCode");
					$requestsSuccess[$orcid] = true;
					break;
				case 201:
					$location = $responseHeaders['Location'][0];
					// Extract the ORCID work put code for updates/deletion.
					$putCode = intval(basename(parse_url($location, PHP_URL_PATH)));
					$this->logInfo("Work added to profile, putCode: $putCode");
					$author->setData('orcidWorkPutCode', $putCode);
					$authorDao->updateObject($author);
					$requestsSuccess[$orcid] = true;
					break;
				case 401:
					// invalid access token, token was revoked
					$error = json_decode($response->getBody(), true);
					if ($error['error'] === 'invalid_token') {
						$this->logError($error['error_description'] . ', deleting orcidAccessToken from author');
						$this->removeOrcidAccessToken($author);
					}
					$requestsSuccess[$orcid] = false;
					break;
				case 403:
					$this->logError('Work update forbidden: ' . $response->getBody());
					$requestsSuccess[$orcid] = false;
					break;
				case 404:
					// a work has been deleted from a ORCID record. putCode is no longer valid.
					if ($method === 'PUT') {
						$this->logError("Work deleted from ORCID record, deleting putCode form author");
						$author->setData('orcidWorkPutCode', null);
						$authorDao->updateObject($author);
						$requestsSuccess[$orcid] = false;
					} else {
						$this->logError("Unexpected status $httpstatus response, body: " . $response->getBody());
						$requestsSuccess[$orcid] = false;
					}
					break;
				case 409:
					$this->logError('Work already added to profile, response body: ' . $response->getBody());
					$requestsSuccess[$orcid] = false;
					break;
				default:
					$this->logError("Unexpected status $httpstatus response, body: " . $response->getBody());
					$requestsSuccess[$orcid] = false;
			}
		}
		if (array_product($requestsSuccess)) {
			return true;
		} else {
			return $requestsSuccess;
		}
	}

	public function buildOrcidReview($submission, $review, $request, $issue = null) {
		$publicationUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'article', 'view', $submission->getId());
		$context = $request->getContext();
		$publicationLocale = ($submission->getData('locale')) ? $submission->getData('locale') : 'en_US';
		$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $context->getId()); // DO not remove
		$supportedSubmissionLocales = $context->getSupportedSubmissionLocales();

		if (!empty($review->getData('dateCompleted'))  && $context->getData('onlineIssn')) {
			$publicationPublishDate = Carbon\Carbon::parse($submission->getData('datePublished'));
			$reviewCompletionDate = Carbon\Carbon::parse($review->getData('dateCompleted'));


			$orcidReview = [
				'reviewer-role' => 'reviewer',
				 'review-type' => 'review',
				"review-completion-date" => [
					"year" => [
						"value" => $reviewCompletionDate->format("Y")
					],
					'month' => [
						'value' => $reviewCompletionDate->format("m")
					],
					'day' => [
						'value' => $reviewCompletionDate->format("d")
					]
				],
				'review-group-id' => "issn:" . $context->getData('onlineIssn'),

				'convening-organization' => [
					'name' => $context->getData('publisherInstitution'),
					'address' => [
						'city' => $this->getSetting($context->getId(), 'city'),
						'country' => $this->getSetting($context->getId(), 'country')

					]
				],
				'review-identifiers' => ['external-id' => [
					[
						'external-id-type' => 'source-work-id',
						'external-id-value' => $review->getData('reviewRoundId'),
						'external-id-relationship' => 'part-of']
				]]
			];
			if ($review->getReviewMethod() == SUBMISSION_REVIEW_METHOD_OPEN) {
				$orcidReview['subject-url'] = ['value' => $publicationUrl];
				$orcidReview['review-url'] = ['value' => $publicationUrl];
				$orcidReview['subject-type'] = 'journal-article';
				$orcidReview['subject-name']= [
					'title' => ['value' => $submission->getCurrentPublication()->getLocalizedData('title') ?? '']
				];


				if (!empty($submission->getData('pub-id::doi'))) {
					$externalIds = [

						'external-id-type' => 'doi',
						'external-id-value' => $submission->getData('pub-id::doi'),
						'external-id-url' => [
							'value' => 'https://doi.org/' . $submission->getData('pub-id::doi')
						],
						'external-id-relationship' => 'self'

					];
					$orcidReview['subject-external-identifier'] = $externalIds;
				}

			}

			$translatedTitleAvailable = false;
			foreach ($supportedSubmissionLocales as $defaultLanguage) {
				if ($defaultLanguage !== $publicationLocale) {
					$iso2LanguageCode = substr($defaultLanguage, 0, 2);
					$defaultTitle = $submission->getLocalizedData($iso2LanguageCode);
					if (strlen($defaultTitle) > 0 && !$translatedTitleAvailable) {
						$orcidReview['subject-name']['translated-title'] = ['value' => $defaultTitle, 'language-code' => $iso2LanguageCode];
						$translatedTitleAvailable = true;
					}
				}

			}
		}
		return $orcidReview;
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
		} else {
			self::writeLog($message, 'INFO');
		}
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
	 * Build an associative array with submission meta data, which can be encoded to a valid ORCID work JSON structure.
	 *
	 * @see https://github.com/ORCID/ORCID-Source/blob/master/orcid-model/src/main/resources/record_2.1/samples/write_sample/bulk-work-2.1.json
	 *  Example of valid ORCID JSON for adding works to an ORCID record.
	 * @param Submission $submission the submission we want to extract data from.
	 * @param Publication $publication extract data from this Article
	 * @param Journal $context Context object the Submission is part of
	 * @param Author[] $authors Array of Author objects, the contributors of the publication
	 * @param Issue|null $issue Issue the Article is part of
	 * @param Request $request the current request
	 * @return array             an associative array with article meta data corresponding to ORCID work JSON structure
	 */
	public function buildOrcidWork($publication, $context, $authors, $request, Issue $issue = null) {
		$submission = Services::get('submission')->get($publication->getData('submissionId'));

		$applicationName = Application::get()->getName();
		$bibtexCitation = '';

		$publicationLocale = ($publication->getData('locale')) ? $publication->getData('locale') : 'en_US';
		$supportedSubmissionLocales = $context->getSupportedSubmissionLocales();

		$publicationUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'article', 'view', $submission->getId());

		$orcidWork = [
			'title' => [
				'title' => [
					'value' => $publication->getLocalizedData('title', $publicationLocale) ?? ''
				],
				'subtitle' => [
					'value' => $publication->getLocalizedData('subtitle', $publicationLocale) ?? ''
				]
			],
			'journal-title' => [
				'value' => $context->getName($publicationLocale) ?? ''
			],
			'short-description' => trim(strip_tags($publication->getLocalizedData('abstract', $publicationLocale))) ?? '',
			'external-ids' => [
				'external-id' => $this->buildOrcidExternalIds($submission, $publication, $context, $issue, $publicationUrl)
			],
			'publication-date' => $this->buildOrcidPublicationDate($publication, $issue),
			'url' => $publicationUrl,
			'language-code' => substr($publicationLocale, 0, 2),
			'contributors' => [
				'contributor' => $this->buildOrcidContributors($authors, $context, $publication)
			]
		];

		if ($applicationName == 'ojs2') {
			PluginRegistry::loadCategory('generic');
			$citationPlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
			/** @var CitationStyleLanguagePlugin $citationPlugin */
			$bibtexCitation = trim(strip_tags($citationPlugin->getCitation($request, $submission, 'bibtex', $issue, $publication)));
			$orcidWork['citation'] = [
				'citation-type' => 'bibtex',
				'citation-value' => $bibtexCitation
			];
			$orcidWork['type'] = 'journal-article';
		}
		if ($applicationName == 'ops') {
			$orcidWork['type'] = 'preprint';
		}

		$translatedTitleAvailable = false;
		foreach ($supportedSubmissionLocales as $defaultLanguage) {
			if ($defaultLanguage !== $publicationLocale) {
				$iso2LanguageCode = substr($defaultLanguage, 0, 2);
				$defaultTitle = $publication->getLocalizedData($iso2LanguageCode);
				if (strlen($defaultTitle) > 0 && !$translatedTitleAvailable) {
					$orcidWork['title']['translated-title'] = ['value' => $defaultTitle, 'language-code' => $iso2LanguageCode];
					$translatedTitleAvailable = true;
				}
			}
		}

		return $orcidWork;
	}

	/**
	 * Write a message with specified level to log
	 *
	 * @param  $message string Message to write
	 * @param  $level   string Error level to add to message
	 * @return void
	 */
	protected static function writeLog($message, $level) {
		$fineStamp = date('Y-m-d H:i:s') . substr(microtime(), 1, 4);
		error_log("$fineStamp $level $message\n", 3, self::logFilePath());
	}

	/**
	 * Build the external identifiers ORCID JSON structure from article, journal and issue meta data.
	 *
	 * @see  https://pub.orcid.org/v2.0/identifiers Table of valid ORCID identifier types.
	 *
	 * @param Submission $submission The Article object for which the external identifiers should be build.
	 * @param Publication $publication The Article object for which the external identifiers should be build.
	 * @param Journal $context Context the Submission is part of.
	 * @param Issue $issue The Issue object the Article object belongs to.
	 * @return array            An associative array corresponding to ORCID external-id JSON.
	 */
	protected function buildOrcidExternalIds($submission, $publication, $context, $issue, $articleUrl) {
		$contextId = $context->getId();

		$externalIds = array();
		$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $contextId);
		// Add doi, urn, etc. for article
		$articleHasStoredPubId = false;
		if (is_array($pubIdPlugins)) {
			foreach ($pubIdPlugins as $plugin) {
				if (!$plugin->getEnabled()) {
					continue;
				}

				$pubIdType = $plugin->getPubIdType();

				# Add article ids
				$pubId = $publication->getData($pubIdType);

				if ($pubId) {
					$externalIds[] = [
						'external-id-type' => self::PUBID_TO_ORCID_EXT_ID[$pubIdType],
						'external-id-value' => $pubId,
						'external-id-url' => [
							'value' => $plugin->getResolvingURL($contextId, $pubId)
						],
						'external-id-relationship' => 'self'
					];

					$articleHasStoredPubId = true;
				}

				# Add issue ids if they exist
				$pubId = $issue->getStoredPubId($pubIdType);
				if ($pubId) {
					$externalIds[] = [
						'external-id-type' => self::PUBID_TO_ORCID_EXT_ID[$pubIdType],
						'external-id-value' => $pubId,
						'external-id-url' => [
							'value' => $plugin->getResolvingURL($contextId, $pubId)
						],
						'external-id-relationship' => 'part-of'
					];
				}
			}
		} else {
			error_log("OrcidProfilePlugin::buildOrcidExternalIds: No pubId plugins could be loaded");
		}

		if (!$articleHasStoredPubId) {
			// No pubidplugins available or article does not have any stored pubid
			// Use URL as an external-id
			$externalIds[] = [
				'external-id-type' => 'uri',
				'external-id-value' => $articleUrl,
				'external-id-relationship' => 'self'
			];
		}

		// Add journal online ISSN
		// TODO What about print ISSN?
		if ($context->getData('onlineIssn')) {
			$externalIds[] = [
				'external-id-type' => 'issn',
				'external-id-value' => $context->getData('onlineIssn'),
				'external-id-relationship' => 'part-of'
			];
		}

		return $externalIds;
	}

	/**
	 * Parse issue year and publication date and use the older on of the two as
	 * the publication date of the ORCID work.
	 *
	 * @return array Associative array with year, month and day or only year
	 */
	protected function buildOrcidPublicationDate($publication, $issue = null) {
		$publicationPublishDate = Carbon\Carbon::parse($publication->getData('datePublished'));

		return [
			'year' => ['value' => $publicationPublishDate->format("Y")],
			'month' => ['value' => $publicationPublishDate->format("m")],
			'day' => ['value' => $publicationPublishDate->format("d")]
		];
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
	protected function buildOrcidContributors($authors, $context, $publication) {
		$contributors = [];
		$first = true;

		foreach ($authors as $author) {
			// TODO Check if e-mail address should be added
			$fullName = $author->getLocalizedGivenName() . " " . $author->getLocalizedFamilyName();

			if (strlen($fullName) == 0) {
				$this->logError("Contributor Name not defined" . $author->getAllData());
			}
			$contributor = [
				'credit-name' => $fullName,
				'contributor-attributes' => [
					'contributor-sequence' => $first ? 'first' : 'additional'
				]
			];

			$userGroup = $author->getUserGroup();
			$role = self::USER_GROUP_TO_ORCID_ROLE[$userGroup->getName('en_US')];

			if ($role) {
				$contributor['contributor-attributes']['contributor-role'] = $role;
			}

			if ($author->getOrcid()) {
				$orcid = basename(parse_url($author->getOrcid(), PHP_URL_PATH));

				if ($author->getData('orcidSandbox')) {
					$uri = ORCID_URL_SANDBOX . $orcid;
					$host = 'sandbox.orcid.org';
				} else {
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
	 * @return string Path to a custom ORCID log file.
	 */
	public static function logFilePath() {
		return Config::getVar('files', 'files_dir') . '/orcid.log';
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
	 * handleEditorAction handles promoting a submission to copyediting.
	 *
	 * @param $hookName string Name the hook was registered with
	 * @param $args array Hook arguments, &$submission, &$editorDecision, &$result, &$recommendation.
	 *
	 * @see EditorAction::recordDecision() The function calling the hook.
	 */
	public function handleEditorAction($hookName, $args)
	{
		$submission = $args[0];
		/** @var Submission $submission */
		$decision = $args[1];

		if ($decision['decision'] == SUBMISSION_EDITOR_DECISION_ACCEPT) {
			$publication = $submission->getCurrentPublication();

			if (isset($publication)) {
				$authorDao = DAORegistry::getDAO('AuthorDAO');
				/** @var AuthorDAO $authorDao */

				$authors = $authorDao->getByPublicationId($submission->getCurrentPublication()->getId());

				foreach ($authors as $author) {
					$orcidAccessExpiresOn = Carbon\Carbon::parse($author->getData('orcidAccessExpiresOn'));
					if ($author->getData('orcidAccessToken') == null || $orcidAccessExpiresOn->isPast()) {
						$this->sendAuthorMail($author, true);
					}
				}
			}

		}
	}

}

