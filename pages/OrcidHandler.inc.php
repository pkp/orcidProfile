<?php

/**
 * @file plugins/generic/orcidProfile/OrcidHandler.inc.php
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class OrcidHandler
 * @ingroup plugins_generic_orcidprofile
 *
 * @brief Pass off internal ORCID API requests to ORCID
 */

import('classes.handler.Handler');

class OrcidHandler extends Handler {
	/**
	 * Authorize handler
	 * @param $args array
	 * @param $request Request
	 */
	function orcidAuthorize($args, &$request) {
		$journal = Request::getJournal();
		$op = Request::getRequestedOp();
		$plugin =& PluginRegistry::getPlugin('generic', 'orcidprofileplugin');

		// fetch the access token
		$curl = curl_init();
        //curl_setopt($curl, CURLOPT_URL, $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath').OAUTH_TOKEN_URL);
		curl_setopt_array($curl, array(
			CURLOPT_URL => $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath').OAUTH_TOKEN_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'code' => $request->getUserVar('code'),
				'grant_type' => 'authorization_code',
				'client_id' => $plugin->getSetting($journal->getId(), 'orcidClientId'),
				'client_secret' => $plugin->getSetting($journal->getId(), 'orcidClientSecret')
			))
		));

		$result = curl_exec($curl);
        $error = curl_error($curl);


		$response = json_decode($result, true);

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL =>  $url = $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($response['orcid']) . '/' . ORCID_PROFILE_URL,
			CURLOPT_POST => false,
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
		));
		$result = curl_exec($curl);
		$info = curl_getinfo($curl);
		if ($info['http_code'] == 200) {
			$json = json_decode($result, true);
		}

        // TODO defstat: Check the $json parameter - Not always full because of error code not always 200.

		switch (Request::getUserVar('targetOp')) {
			case 'register':
				echo '<html><body><script type="text/javascript">
					opener.document.getElementById("firstName").value = ' . json_encode($json['orcid-profile']['orcid-bio']['personal-details']['given-names']['value']) . ';
					opener.document.getElementById("lastName").value = ' . json_encode($json['orcid-profile']['orcid-bio']['personal-details']['family-name']['value']) . ';
					opener.document.getElementById("email").value = ' . json_encode($json['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value']) . ';
					opener.document.getElementById("orcid").value = ' . json_encode('http://orcid.org/' . $response['orcid']). ';
					opener.document.getElementById("connect-orcid-button").style.display = "none";
					window.close();
				</script></body></html>';
				break;
			case 'profile':
				// Set the ORCiD in the user profile from the response
				echo '<html><body><script type="text/javascript">
					opener.document.getElementById("orcid").value = ' . json_encode('http://orcid.org/' . $response['orcid']). ';
					opener.document.getElementById("connect-orcid-button").style.display = "none";
					window.close();
				</script></body></html>';
				break;
			case 'submit':
				// Submission process: Pre-fill the first author's ORCiD from the ORCiD data
				echo '<html><body><script type="text/javascript">
					opener.document.getElementById("authors-0-orcid").value = ' . json_encode('http://orcid.org/' . $response['orcid']). ';
					opener.document.getElementById("connect-orcid-button").style.display = "none";
					window.close();
				</script></body></html>';
				break;
            case 'login':
                if (!is_null($json)) {
                    // The user that will be logged in
                    $loggedInUser = null;

                    // Check if there is any user that has autoassigned orcidauth parameter on the UserSettings.
                    $userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
                    $userDao = DAORegistry::getDAO('UserDAO');
                    $users = $userSettingsDao->getUsersBySetting('orcidauth', 'http://orcid.org/' . $response['orcid']);

                    if (is_null($users) || $users->count == 0) { // If no user exists
                        // Then we should look for someone that has his orcid field filled.
                        $users = $userSettingsDao->getUsersBySetting('orcid', 'http://orcid.org/' . $response['orcid']);

                        if (is_null($users) || $users->count == 0) { // If no user exists
                            // Then we can look if there is any user with the email assigned to any email from ORCID profile

                            // get all emails
                            $emails = $json['orcid-profile']['orcid-bio']['contact-details']['email'];
                            if (!is_null($emails)) { // No emails retrieved from api. Email field may not be public
                                foreach($emails as $email) {
                                    $user->$userDao->getUserByEmail($email, false);

                                    if (!is_null($user)) {
                                        $loggedInUser = $user;
                                        break;
                                    }
                                }
                            }
                        } else { // we have at least one user with his orcid field filled
                            if (count($users) != 1) { // There are more than one users with that orcidauth. Nothing we can do. Loggin fails
                                $loggedInUser = false;
                                Validation::redirectLogin('plugins.generic.oauth.message.oauthTooManyMatches');
                            } else { // only one user has the current orcidauth. We can log him in.
                                $loggedInUser = $users->next();
                            }
                        }

                    } else { // There is at least one user with its orcidauth assigned to the current value
                        if ($users->count != 1) { // There are more than one users with that orcidauth. Nothing we can do. Loggin fails
                            $loggedInUser = false;
                            Validation::redirectLogin('plugins.generic.oauth.message.oauthTooManyMatches');
                        } else { // only one user has the current orcidauth. We can log him in.
                            $loggedInUser = $users->next();
                        }

                    }

                    if ($loggedInUser) {
                        $userDao =& DAORegistry::getDAO('UserDAO');

                        $reason = null;
                        // The user is valid, mark user as logged in in current session
                        $sessionManager =& SessionManager::getManager();

                        // Regenerate session ID first
                        $sessionManager->regenerateSessionId();

                        $session =& $sessionManager->getUserSession();
                        $session->setSessionVar('userId', $loggedInUser->getId());
                        $session->setUserId($loggedInUser->getId());
                        $session->setSessionVar('username', $loggedInUser->getUsername());
                        //$session->setRemember($remember);

                        $loggedInUser->setDateLastLogin(Core::getCurrentDate());
                        $userDao->updateObject($loggedInUser);

                        Validation::redirectLogin();
                    } else { // OAuth successful, but not linked to a user account (yet)
                        $sessionManager = SessionManager::getManager();
                        $userSession = $sessionManager->getUserSession();
                        $user = $userSession->getUser();

                        if (isset($user)) {
                            // If the user is authenticated, link this user account
                            $userSettingsDao->updateSetting($user->getId(), 'orcidauth', 'http://orcid.org/' . $response['orcid'], 'string');
                            $userSettingsDao->updateSetting($user->getId(), 'orcid', 'http://orcid.org/' . $response['orcid'], 'string');
                        } else {
                            // Otherwise, send the user to the login screen (keep track of the oauthUniqueId to link upon login!)
                            $userSession->setSessionVar('orcidauth', 'http://orcid.org/' . $response['orcid']);
                        }
                    }

                    Validation::redirectLogin('plugins.generic.oauth.message.oauthLoginError');
                }

                Validation::redirectLogin('plugins.generic.oauth.message.oauthTooManyMatches');

				break;
			default: assert(false);
		}
	}

	/**
	 * Verify an incoming author claim for an ORCiD association.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function orcidVerify($args, $request) {
		$journal = Request::getJournal();
		$op = Request::getRequestedOp();
		$plugin =& PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		$templateMgr =& TemplateManager::getManager($request);

		// fetch the access token
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $plugin->getSetting($journal->getId(), 'orcidProfileAPIPath').OAUTH_TOKEN_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'code' => Request::getUserVar('code'),
				'grant_type' => 'authorization_code',
				'client_id' => $plugin->getSetting($journal->getId(), 'orcidClientId'),
				'client_secret' => $plugin->getSetting($journal->getId(), 'orcidClientSecret')
			))
		));
		$result = curl_exec($curl);
		$response = json_decode($result, true);

		if (!isset($response['orcid'])) {
			$templateMgr->assign(array(
				'currentUrl' => $request->url(null, 'index'),
				'pageTitle' => 'plugins.generic.orcidProfile.author.submission',
				'message' => 'plugins.generic.orcidProfile.authFailure',
			));
			$templateMgr->display('common/message.tpl');
			exit();
		}

		$authorDao =& DAORegistry::getDAO('AuthorDAO');
		$authors =& $authorDao->getAuthorsBySubmissionId($request->getUserVar('articleId'));
		foreach ($authors as $author) {
			if ($author->getData('orcidToken') == $request->getUserVar('orcidToken')) {
				$author->setData('orcid', 'http://orcid.org/' . $response['orcid']);
				$author->setData('orcidToken', null);
				$authorDao->updateAuthor($author);

				$templateMgr->assign(array(
					'currentUrl' => $request->url(null, 'index'),
					'pageTitle' => 'plugins.generic.orcidProfile.author.submission',
					'message' => 'plugins.generic.orcidProfile.author.submission.success',
				));
				$templateMgr->display('common/message.tpl');
				exit();
			}
		}

		$templateMgr->assign(array(
			'currentUrl' => $request->url(null, 'index'),
			'pageTitle' => 'plugins.generic.orcidProfile.author.submission',
			'message' => 'plugins.generic.orcidProfile.author.submission.failure',
		));
		$templateMgr->display('common/message.tpl');
	}


}

?>
