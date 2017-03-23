<?php

/**
 * @file plugins/generic/orcidProfile/OrcidHandler.inc.php
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 * 
 * Contributed by 4Science. 
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
					opener.document.getElementById("remove-orcid-button-0").style.display = "inline";
					window.close();
				</script></body></html>';
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
                            $query .= '(given-names:' . $name . '+AND+family-name:' . $lastname . ')+OR+email:' . $email;
                        } elseif ($name && $lastname) {
                            $query .= 'given-names:' . $name . '+AND+family-name:' . $lastname;
                        } else {
                            $query .= 'email:' . $email;
                        }
                        
                        // Performing search
                        $curl = curl_init();
                        curl_setopt_array($curl, array(
                            CURLOPT_FAILONERROR    => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => array('Accept: application/json',
                                                        'Content-Type: application/orcid+xml',
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
}

?>
