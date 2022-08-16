<?php

/**
 * @file pages/OrcidHandler.inc.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class OrcidHandler
 * @ingroup plugins_generic_orcidprofile
 *
 * @brief Pass off internal ORCID API requests to ORCID
 */

import('classes.handler.Handler');

class OrcidHandler extends Handler
{
	const TEMPLATE = 'orcidVerify.tpl';

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments)
	{
		// Authorize all requets
		import('lib.pkp.classes.security.authorization.PKPSiteAccessPolicy');
		$this->addPolicy(new PKPSiteAccessPolicy(
			$request,
			array('orcidVerify', 'orcidAuthorize', 'about'),
			SITE_ACCESS_ALL_ROLES
		));

		$op = $request->getRequestedOp();
		$targetOp = $request->getUserVar('targetOp');
		if ($op === 'orcidAuthorize' && in_array($targetOp, ['profile', 'submit'])) {
			// ... but user must be logged in for orcidAuthorize with profile or submit
			import('lib.pkp.classes.security.authorization.UserRequiredPolicy');
			$this->addPolicy(new UserRequiredPolicy($request));
		}

		if (!Config::getVar('general', 'installed')) define('SESSION_DISABLE_INIT', true);

		$this->setEnforceRestrictedSite(false);
		return parent::authorize($request, $args, $roleAssignments);
	}


	/**
	 * Authorize handler
	 * @param $args array
	 * @param $request Request
	 */
	function orcidAuthorize($args, $request)
	{
		$context = $request->getContext();
		$op = $request->getRequestedOp();
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$contextId = ($context == null) ? CONTEXT_ID_NONE : $context->getId();
		$httpClient = Application::get()->getHttpClient();

		// API request: Get an OAuth token and ORCID.
		$response = $httpClient->request(
			'POST',
			$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . OAUTH_TOKEN_URL,
			[
				'form_params' => [
					'code' => $request->getUserVar('code'),
					'grant_type' => 'authorization_code',
					'client_id' => $plugin->getSetting($contextId, 'orcidClientId'),
					'client_secret' => $plugin->getSetting($contextId, 'orcidClientSecret')
				],
				'headers' => ['Accept' => 'application/json'],
			]
		);
		if ($response->getStatusCode() != 200) {
			error_log('ORCID token URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
			$orcidUri = $orcid = $accessToken = null;
		} else {
			$response = json_decode($response->getBody(), true);
			$orcid = $response['orcid'];
			$accessToken = $response['access_token'];
			$orcidUri = ($plugin->getSetting($contextId, "isSandBox") == true ? ORCID_URL_SANDBOX : ORCID_URL) . $orcid;
		}

		switch ($request->getUserVar('targetOp')) {
			case 'register':
				// API request: get user profile (for names; email; etc)
				$response = $httpClient->request(
					'GET',
					$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_PROFILE_URL,
					[
						'headers' => [
							'Accept' => 'application/json',
							'Authorization' => 'Bearer ' . $accessToken,
						],
					]
				);
				if ($response->getStatusCode() != 200) {
					error_log('ORCID profile URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
					$profileJson = null;
				} else $profileJson = json_decode($response->getBody(), true);

				// API request: get employments (for affiliation field)
				$httpClient->request(
					'GET',
					$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_EMPLOYMENTS_URL,
					[
						'headers' => [
							'Accept' => 'application/json',
							'Authorization' => 'Bearer ' . $accessToken,
						],
					]
				);
				if ($response->getStatusCode() != 200) {
					error_log('ORCID deployment URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
					$employmentJson = null;
				} else $employmentJson = json_decode($response->getBody(), true);

				// Suppress errors for nonexistent array indexes
				echo '
					<html><body><script type="text/javascript">
					opener.document.getElementById("givenName").value = ' . json_encode(@$profileJson['name']['given-names']['value']) . ';
					opener.document.getElementById("familyName").value = ' . json_encode(@$profileJson['name']['family-name']['value']) . ';
					opener.document.getElementById("email").value = ' . json_encode(@$profileJson['emails']['email'][0]['email']) . ';
					opener.document.getElementById("country").value = ' . json_encode(@$profileJson['addresses']['address'][0]['country']['value']) . ';
					opener.document.getElementById("affiliation").value = ' . json_encode(@$employmentJson['employment-summary'][0]['organization']['name']) . ';
					opener.document.getElementById("orcid").value = ' . json_encode($orcidUri) . ';
					opener.document.getElementById("connect-orcid-button").style.display = "none";
					window.close();
					</script></body></html>
				';
				break;
			case 'profile':
				$user = $request->getUser();
				// Store the access token and other data for the user
				$this->_setOrcidData($user, $orcidUri, $response);
				$userDao = DAORegistry::getDAO('UserDAO');
				$userDao->updateLocaleFields($user);

				// Reload the public profile tab (incl. form)
				echo '
					<html><body><script type="text/javascript">
						opener.$("#profileTabs").tabs("load", 3);
						window.close();
					</script></body></html>
				';
				break;
			default:
				assert(false);
		}
	}

	function _setOrcidData($userOrAuthor, $orcidUri, $orcidResponse)
	{
		// Save the access token
		$orcidAccessExpiresOn = Carbon\Carbon::now();
		// expires_in field from the response contains the lifetime in seconds of the token
		// See https://members.orcid.org/api/get-oauthtoken
		$orcidAccessExpiresOn->addSeconds($orcidResponse['expires_in']);
		$userOrAuthor->setOrcid($orcidUri);
		// remove the access denied marker, because now the access was granted
		$userOrAuthor->setData('orcidAccessDenied', null);
		$userOrAuthor->setData('orcidAccessToken', $orcidResponse['access_token']);
		$userOrAuthor->setData('orcidAccessScope', $orcidResponse['scope']);
		$userOrAuthor->setData('orcidRefreshToken', $orcidResponse['refresh_token']);
		$userOrAuthor->setData('orcidAccessExpiresOn', $orcidAccessExpiresOn->toDateTimeString());
	}

	/**
	 * Verify an incoming author claim for an ORCiD association.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function orcidVerify($args, $request)
	{
		$templateMgr = TemplateManager::getManager($request);
		$context = $request->getContext();
		$contextId = ($context == null) ? CONTEXT_ID_NONE : $context->getId();

		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$templatePath = $plugin->getTemplateResource(self::TEMPLATE);


		$publicationId = $request->getUserVar('state');
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$authors = $authorDao->getByPublicationId($publicationId);
		$isSandBox = $plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_MEMBER_SANDBOX ||
			$plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_PUBLIC_SANDBOX;


		$publication = Services::get('publication')->get($publicationId);

		$authorToVerify = null;
		// Find the author entry, for which the ORCID verification was requested
		if ($request->getUserVar('token')) {
			foreach ($authors as $author) {
				if ($author->getData('orcidEmailToken') == $request->getUserVar('token')) {
					$authorToVerify = $author;
				}
			}
		}

		// Initialise template parameters
		$templateMgr->assign(array(
			'currentUrl' => $request->url(null, 'index'),
			'verifySuccess' => false,
			'authFailure' => false,
			'notPublished' => false,
			'sendSubmission' => false,
			'sendSubmissionSuccess' => false,
			'denied' => false,
		));

		if ($authorToVerify == null) {
			// no Author exists in the database with the supplied orcidEmailToken
			$plugin->logError('OrcidHandler::orcidverify - No author found with supplied token');
			$templateMgr->assign('verifySuccess', false);
			$templateMgr->display($templatePath);
			return;
		}

		if ($request->getUserVar('error') === 'access_denied') {
			// User denied access
			// Store the date time the author denied ORCID access to remember this
			$authorToVerify->setData('orcidAccessDenied', Core::getCurrentDate());
			// remove all previously stored ORCID access token
			$authorToVerify->setData('orcidAccessToken', null);
			$authorToVerify->setData('orcidAccessScope', null);
			$authorToVerify->setData('orcidRefreshToken', null);
			$authorToVerify->setData('orcidAccessExpiresOn', null);
			$authorToVerify->setData('orcidEmailToken', null);
			$authorDao->updateLocaleFields($authorToVerify);
			$plugin->logError('OrcidHandler::orcidverify - ORCID access denied. Error description: ' . $request->getUserVar('error_description'));
			$templateMgr->assign('denied', true);
			$templateMgr->display($templatePath);
			return;
		}

		// fetch the access token
		$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . OAUTH_TOKEN_URL;

		$httpClient = Application::get()->getHttpClient();
		$header = ['Accept' => 'application/json'];
		$postData = [
			'code' => $request->getUserVar('code'),
			'grant_type' => 'authorization_code',
			'client_id' => $plugin->getSetting($contextId, 'orcidClientId'),
			'client_secret' => $plugin->getSetting($contextId, 'orcidClientSecret')
		];

		$plugin->logInfo('POST ' . $url);
		$plugin->logInfo('Request header: ' . var_export($header, true));
		$plugin->logInfo('Request body: ' . http_build_query($postData));
		$responseJson = [];
		try {
			$response = $httpClient->request(
				'POST',
				$url,
				[
					'headers' => $header,
					'form_params' => $postData,
				]
			);

			$responseJson = json_decode($response->getBody(), true);

			$plugin->logInfo('Response body: ' . print_r($responseJson, true));


		} catch (GuzzleHttp\Exception\RequestException  $exception) {

			$plugin->logInfo("Publication fail:  " . $exception->getMessage());
			$templateMgr->assign('orcidAPIError', $exception->getMessage());
			$templateMgr->display($templatePath);
		}

		// Set the orcid id using the full https uri
		$orcidUri = ($isSandBox ? ORCID_URL_SANDBOX : ORCID_URL) . $responseJson['orcid'];

		if ($response->getStatusCode() == 200 && strlen($responseJson['orcid']) > 0) {
			$authorToVerify->setOrcid($orcidUri);
			if ($isSandBox) $authorToVerify->setData('orcidSandbox', true);
			$templateMgr->assign('orcid', $orcidUri);
			// remove the email token
			$authorToVerify->setData('orcidEmailToken', null);
			$this->_setOrcidData($authorToVerify, $orcidUri, $responseJson);
			$authorDao->updateObject($authorToVerify);
			if ($plugin->isMemberApiEnabled($contextId)) {
				if ($publication->getData('status') == STATUS_PUBLISHED) {
					$templateMgr->assign('sendSubmission', true);
					$sendResult = $plugin->sendSubmissionToOrcid($publication, $request);
					if ($sendResult === true || (is_array($sendResult) && $sendResult[$responseJson['orcid']])) {
						$templateMgr->assign('sendSubmissionSuccess', true);
					}
				} else {
					$templateMgr->assign('submissionNotPublished', true);
				}
			}

			$templateMgr->assign(array(
				'verifySuccess' => true,
				'orcidIcon' => $plugin->getIcon()
			));
		} else {
			$plugin->logError('OrcidHandler::orcidverify - unexpected response: ' . $response->getStatusCode());
			$templateMgr->assign('authFailure', true);
			$templateMgr->assign('orcidAPIError', $response->getReasonPhrase());
			$templateMgr->display($templatePath);
		}


		if (!empty($authorToVerify->getOrcid()) && $orcidUri != $authorToVerify->getOrcid()) {
			// another ORCID id is stored for the author
			$templateMgr->assign('duplicateOrcid', true);
			$templateMgr->display($templatePath);
			return;
		}

		$templateMgr->display($templatePath);
	}

	/**
	 * Show explanation and information about ORCID
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function about($args, $request)
	{
		$context = $request->getContext();
		$contextId = ($context == null) ? CONTEXT_ID_NONE : $context->getId();
		$templateMgr = TemplateManager::getManager($request);
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$templateMgr->assign('orcidIcon', $plugin->getIcon());
		$templateMgr->assign('isMemberApi', $plugin->isMemberApiEnabled($contextId));
		$templateMgr->display($plugin->getTemplateResource('orcidAbout.tpl'));
	}
}

