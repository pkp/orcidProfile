<?php

/**
 * @file plugins/generic/orcidProfile/pages/OrcidHandler.inc.php
 *
 * Copyright (c) 2015-2017 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 * 
 * Contributed by 4Science (http://www.4science.it). 
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
		$response = $plugin->fetchAccessToken(Request::getUserVar('code'));

		$profile = $plugin->getOrcidProfile($response['orcid']);
		switch (Request::getUserVar('targetOp')) {
			case 'register':
				echo '<html><body><script type="text/javascript">
					opener.document.getElementById("firstName").value = ' . json_encode($profile['orcid-profile']['orcid-bio']['personal-details']['given-names']['value']) . ';
					opener.document.getElementById("lastName").value = ' . json_encode($profile['orcid-profile']['orcid-bio']['personal-details']['family-name']['value']) . ';
					opener.document.getElementById("email").value = ' . json_encode($profile['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value']) . ';
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
		$response = $plugin->fetchAccessToken(Request::getUserVar('code'));

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
				$journal = Request::getJournal();
				$totalResults = 0;
				$orcidSearchResults = array();

				$searchName = $request->getUserVar('searchOrcidName');
				$searchLastname = $request->getUserVar('searchOrcidLastname');
				$searchEmail = $request->getUserVar('searchOrcidEmail');
				$profilesPage = (int) $request->getUserVar('profilesPage');
				$itemsPerPage = $plugin->getSetting($journal->getId(), 'itemsPerPage');

				$templateMgr->assign_by_ref('itemsPerPage', $itemsPerPage);

				$result = $plugin->searchProfile($searchName, $searchLastname, $searchEmail, $profilesPage, $itemsPerPage);

				if ($result) {
					// Processing results
					$totalResults = $result['orcid-search-results']['num-found'];
					if ($totalResults > 0) {
						foreach($result['orcid-search-results']['orcid-search-result'] as $resultItem) {
							$name = $resultItem['orcid-profile']['orcid-bio']['personal-details']['given-names']['value'];
							$lastname = $resultItem['orcid-profile']['orcid-bio']['personal-details']['family-name']['value'];
							$email = isset($resultItem['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value'])?$resultItem['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value']:null;                                
							$orcidUri = $resultItem['orcid-profile']['orcid-identifier']['uri'];
							$orcidiD = $resultItem['orcid-profile']['orcid-identifier']['path'];

							$profile = $plugin->getOrcidProfile($orcidiD);

							$researcherUrls = array();
							if (!is_null($profile['orcid-profile']['orcid-bio']['researcher-urls'])) {
								foreach ($profile['orcid-profile']['orcid-bio']['researcher-urls']['researcher-url'] as $researcherUrl) {
									$researcherUrls[] = $researcherUrl['url-name']['value'] . ' - ' . $researcherUrl['url']['value'];    
								}
							}

							$affiliations = array();
							if (!is_null($profile['orcid-profile']['orcid-activities']['affiliations'])) {
								foreach ($profile['orcid-profile']['orcid-activities']['affiliations']['affiliation'] as $affiliation) {
									$affiliations[] = $affiliation['organization']['name'];
								}
							}

							$orcidSearchResults[] = array(
								'name' => $name,
								'lastname' => $lastname,
								'email' => $email,
								'orcidiD' => $orcidUri,
								'affiliations' => $affiliations,
								'researcherUrls' => $researcherUrls
							);
						}
					}
				}

				// Paginate results.
				// Instantiate article iterator.
				import('lib.pkp.classes.core.VirtualArrayIterator');
				$iterator = new VirtualArrayIterator($orcidSearchResults, $totalResults, $profilesPage, $itemsPerPage);

				// Prepare and display the article template.
				$templateMgr->assign_by_ref('orcidSearchResults', $iterator);
				$templateMgr->assign_by_ref('searchOrcidName', $searchName);
				$templateMgr->assign_by_ref('searchOrcidLastname', $searchLastname);
				$templateMgr->assign_by_ref('searchOrcidEmail', $searchEmail);
				$templateMgr->display($plugin->getTemplatePath() . 'orcidProfileSearchResults.tpl');

				break;
			default: assert(false);
		}
	}
}

?>
