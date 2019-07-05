<?php

/**
 * @file plugins/generic/orcidProfile/OrcidHandler.inc.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class OrcidHandler
 * @ingroup plugins_generic_orcidprofile
 *
 * @brief Pass off internal ORCID API requests to ORCID
 */

import('classes.handler.Handler');

class OrcidHandler extends Handler {
	const TEMPLATE = 'orcidVerify.tpl';

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
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
	function orcidAuthorize($args, $request) {
		$context = $request->getContext();
		$op = $request->getRequestedOp();
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$contextId = ($context == null) ? CONTEXT_ID_NONE : $context->getId();

		// Set up common CURL request details
		$curl = curl_init();
		// Use proxy if configured
		if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
			curl_setopt($curl, CURLOPT_PROXY, $httpProxyHost);
			curl_setopt($curl, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));
			if ($username = Config::getVar('proxy', 'username')) {
				curl_setopt($curl, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
			}
		}

		// API request: Get an OAuth token and ORCID.
		curl_setopt_array($curl, array(
			CURLOPT_URL => $plugin->getSetting($contextId, 'orcidProfileAPIPath') . OAUTH_TOKEN_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'code' => $request->getUserVar('code'),
				'grant_type' => 'authorization_code',
				'client_id' => $plugin->getSetting($contextId, 'orcidClientId'),
				'client_secret' => $plugin->getSetting($contextId, 'orcidClientSecret')
			))
		));
		if (!($result = curl_exec($curl))) {
			error_log('ORCID CURL error: ' . curl_error($curl) . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
			$orcidUri = $orcid = $accessToken = null;
		} else {
			$response = json_decode($result, true);
			$orcid = $response['orcid'];
			$accessToken = $response['access_token'];
			$orcidUri = 'https://orcid.org/' . $orcid;
		}

		switch ($request->getUserVar('targetOp')) {
			case 'register':
				// API request: get user profile (for names; email; etc)
				curl_setopt_array($curl, array(
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_URL =>	$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_PROFILE_URL,
					CURLOPT_POST => false,
					CURLOPT_HTTPHEADER => array(
						'Accept: application/json',
						'Authorization: Bearer ' . $accessToken,
					),
				));
				if (!($result = curl_exec($curl))) error_log('ORCID CURL error: ' . curl_error($curl) . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
				$info = curl_getinfo($curl);
				if ($info['http_code'] == 200) {
					$profileJson = json_decode($result, true);
				} else {
					error_log('Unexpected ORCID API response: ' . $info['http_code'] . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
					$profileJson = null;
				}

				// API request: get employments (for affiliation field)
				curl_setopt_array($curl, array(
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_URL =>	$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_EMPLOYMENTS_URL,
					CURLOPT_POST => false,
					CURLOPT_HTTPHEADER => array(
						'Accept: application/json',
						'Authorization: Bearer ' . $accessToken,
					),
				));
				if (!($result = curl_exec($curl))) error_log('ORCID CURL error: ' . curl_error($curl) . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
				$info = curl_getinfo($curl);
				if ($info['http_code'] == 200) {
					$employmentJson = json_decode($result, true);
				} else {
					error_log('Unexpected ORCID API response: ' . $info['http_code'] . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
					$employmentJson = null;
				}

				// Suppress errors for nonexistent array indexes
				echo '
					<html><body><script type="text/javascript">
					opener.document.getElementById("givenName").value = ' . json_encode(@$profileJson['name']['given-names']['value']) . ';
					opener.document.getElementById("familyName").value = ' . json_encode(@$profileJson['name']['family-name']['value']) . ';
					opener.document.getElementById("email").value = ' . json_encode(@$profileJson['emails']['email'][0]['email']) . ';
					opener.document.getElementById("country").value = ' . json_encode(@$profileJson['addresses']['address'][0]['country']['value']) . ';
					opener.document.getElementById("affiliation").value = ' . json_encode(@$employmentJson['employment-summary'][0]['organization']['name']) . ';
					opener.document.getElementById("orcid").value = ' . json_encode($orcidUri). ';
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
			default: assert(false);
		}

		curl_close($curl);
	}

	/**
	 * Verify an incoming author claim for an ORCiD association.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function orcidVerify($args, $request) {
		$context = $request->getContext();
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$templateMgr = TemplateManager::getManager($request);
		$contextId = ($context == null) ? CONTEXT_ID_NONE : $context->getId();
		$submissionId = $request->getUserVar('articleId');
		$authorDao = DAORegistry::getDAO('AuthorDAO');
		$authors = $authorDao->getBySubmissionId($submissionId);
		$templatePath = $plugin->getTemplateResource(self::TEMPLATE);
		$authorToVerify = null;
		// Find the author entry, for which the ORCID verification was requested
		if($request->getUserVar('token')) {
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
			$plugin->logError('OrcidHandler::orcidverify - ORCID access denied. Error description: '
				. $request->getUserVar('error_description'));
			$templateMgr->assign('denied', true);
			$templateMgr->display($templatePath);
			return;
		}

		// fetch the access token
		$url = $plugin->getSetting($contextId, 'orcidProfileAPIPath').OAUTH_TOKEN_URL;
		$header = array('Accept: application/json');
		$ch = curl_init($url);
		$postData = http_build_query(array(
			'code' => $request->getUserVar('code'),
			'grant_type' => 'authorization_code',
			'client_id' => $plugin->getSetting($contextId, 'orcidClientId'),
			'client_secret' => $plugin->getSetting($contextId, 'orcidClientSecret')
		));
		$plugin->logInfo('POST ' . $url);
		$plugin->logInfo('Request header: ' . var_export($header, true));
		$plugin->logInfo('Request body: ' . $postData);
		// Use proxy if configured
		if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
			curl_setopt($ch, CURLOPT_PROXY, $httpProxyHost);
			curl_setopt($ch, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));
			if ($username = Config::getVar('proxy', 'username')) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
			}
		}
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $header,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $postData
		));
		if (!($result = curl_exec($ch))) {
			$plugin->logError('OrcidHandler::orcidverify - CURL error: ' . curl_error($ch));
			$templateMgr->assign('authFailure', true);
			$templateMgr->display($templatePath);
			return;
		}
		$httpstatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$plugin->logInfo('Response body: ' . $result);
		$response = json_decode($result, true);
		if ($response['error'] === 'invalid_grant') {
			$plugin->logError("Response status: $httpstatus . Authroization code invalid, maybe already used");
			$templateMgr->assign('authFailure', true);
			$templateMgr->display($templatePath);
			return;
		} elseif (isset($response['error'])) {
			$plugin->logError("Response status: $httpstatus . Invalid ORCID response: $result");
			$templateMgr->assign('authFailure', true);
			$templateMgr->display($templatePath);
		}
		// Set the orcid id using the full https uri
		$orcidUri = 'https://orcid.org/' . $response['orcid'];
		if (!empty($authorToVerify->getOrcid()) && $orcidUri != $authorToVerify->getOrcid()) {
			// another ORCID id is stored for the author
			$templateMgr->assign('duplicateOrcid', true);
			$templateMgr->display($templatePath);
			return;
		}
		$authorToVerify->setOrcid($orcidUri);
		if ($plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_MEMBER_SANDBOX ||
			$plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_PUBLIC_SANDBOX) {
			// Set a flag to mark that the stored orcid id and access token came form the sandbox api
			$authorToVerify->setData('orcidSandbox', true);
			$templateMgr->assign('orcid', 'https://sandbox.orcid.org/' . $response['orcid']);
		} else {
			$templateMgr->assign('orcid', $orcidUri);
		}
		// remove the email token
		$authorToVerify->setData('orcidEmailToken', null);
		$this->_setOrcidData($authorToVerify, $orcidUri, $response);
		$authorDao->updateObject($authorToVerify);
		if( $plugin->isMemberApiEnabled($contextId) ) {
			if ( $plugin->isSubmissionPublished($submissionId) ) {
				$templateMgr->assign('sendSubmission', true);
				$sendResult = $plugin->sendSubmissionToOrcid($submissionId, $request);
				if ($sendResult === true || (is_array($sendResult) && $sendResult[$response['orcid']])) {
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
		$templateMgr->display($templatePath);
	}

	function _setOrcidData($userOrAuthor, $orcidUri, $orcidResponse) {
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

	/*
	 * Show explanation and information about ORCID
	 */

	function about($args, $request) {
		$templateMgr = TemplateManager::getManager($request);
		$context = $request->getContext();
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$templateMgr->assign('contextName', $context->getLocalizedName());
		$templateMgr->assign('memberApiEnabled', $plugin->isMemberApiEnabled($context->getId()));
		$templateMgr->assign('orcidIcon', $plugin->getIcon());
		$templateMgr->display($plugin->getTemplateResource('orcidAbout.tpl'));
	}
}

