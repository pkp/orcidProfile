<?php

/**
 * @file OrcidProfileHandler.php
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfileHandler
 *
 * @ingroup plugins_generic_orcidprofile
 *
 * @brief Pass off internal ORCID API requests to ORCID
 */

namespace APP\plugins\generic\orcidProfile;

use APP\core\Application;
use PKP\config\Config;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\template\TemplateManager;
use Carbon\Carbon;
use Exception;
use PKP\core\Core;
use PKP\core\PKPRequest;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\session\SessionManager;
use PKP\submission\PKPSubmission;

class OrcidProfileHandler extends Handler
{
    public const TEMPLATE = 'orcidVerify.tpl';
    public const ORCIDPROFILEPLUGIN = 'orcidprofileplugin';
    private bool $isSandBox;
    private OrcidProfilePlugin $plugin;

    public function __construct()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = $context?->getId() ?? \PKP\core\PKPApplication::CONTEXT_ID_NONE;
        /** @var OrcidProfilePlugin */
        $plugin = PluginRegistry::getPlugin('generic', self::ORCIDPROFILEPLUGIN);
        $this->plugin = $plugin;
        $this->isSandBox = $this->plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_MEMBER_SANDBOX ||
            $this->plugin->getSetting($contextId, 'orcidProfileAPIPath') == ORCID_API_URL_PUBLIC_SANDBOX;
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        // Authorize all requests
        $this->addPolicy(new PKPSiteAccessPolicy(
            $request,
            ['orcidVerify', 'orcidAuthorize', 'about'],
            PKPSiteAccessPolicy::SITE_ACCESS_ALL_ROLES
        ));

        $op = $request->getRequestedOp();
        $targetOp = $request->getUserVar('targetOp');
        if ($op === 'orcidAuthorize' && in_array($targetOp, ['profile', 'submit'])) {
            // ... but user must be logged in for orcidAuthorize with profile or submit
            $this->addPolicy(new UserRequiredPolicy($request));
        }

        if (!Application::isInstalled()) {
            SessionManager::disable();
        }

        $this->setEnforceRestrictedSite(false);
        return parent::authorize($request, $args, $roleAssignments);
    }


    /**
     * Authorize handler
     *
     * @param array $args
     * @param Request $request
     */
    public function orcidAuthorize($args, $request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with orcid service');
            return;
        }

        $context = $request->getContext();
        $contextId = $context?->getId() ?? \PKP\core\PKPApplication::CONTEXT_ID_NONE;
        $httpClient = Application::get()->getHttpClient();

        // API request: Get an OAuth token and ORCID.
        $response = $httpClient->request(
            'POST',
            $url = $this->plugin->getOrcidUrl() . OAUTH_TOKEN_URL,
            [
                'form_params' => [
                    'code' => $request->getUserVar('code'),
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->plugin->getSetting($contextId, 'orcidClientId'),
                    'client_secret' => $this->plugin->getSetting($contextId, 'orcidClientSecret')
                ],
                'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => ['strict' => true],
            ]
        );
        if ($response->getStatusCode() != 200) {
            error_log('ORCID token URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
            $orcidUri = $orcid = $accessToken = null;
        } else {
            $response = json_decode($response->getBody(), true);
            $orcid = $response['orcid'];
            $accessToken = $response['access_token'];
            $orcidUri = ($this->isSandBox == true ? ORCID_URL_SANDBOX : ORCID_URL) . $orcid;
        }

        switch ($request->getUserVar('targetOp')) {
            case 'register':
                // API request: get user profile (for names; email; etc)
                $response = $httpClient->request(
                    'GET',
                    $url = $this->plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_PROFILE_URL,
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                        'allow_redirects' => ['strict' => true],
                    ]
                );
                if ($response->getStatusCode() != 200) {
                    error_log('ORCID profile URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
                    $profileJson = null;
                } else {
                    $profileJson = json_decode($response->getBody(), true);
                }

                // API request: get employments (for affiliation field)
                $httpClient->request(
                    'GET',
                    $url = $this->plugin->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . urlencode($orcid) . '/' . ORCID_EMPLOYMENTS_URL,
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                        'allow_redirects' => ['strict' => true],
                    ]
                );
                if ($response->getStatusCode() != 200) {
                    error_log('ORCID deployments URL error: ' . $response->getStatusCode() . ' (' . __FILE__ . ' line ' . __LINE__ . ', URL ' . $url . ')');
                    $employmentJson = null;
                } else {
                    $employmentJson = json_decode($response->getBody(), true);
                }

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
                Repo::user()->edit($user, ['orcidAccessDenied', 'orcidAccessToken', 'orcidAccessScope', 'orcidRefreshToken', 'orcidAccessExpiresOn']);

                // Reload the public profile tab (incl. form)
                echo '
                    <html><body><script type="text/javascript">
                        opener.$("#profileTabs").tabs("load", 3);
                        window.close();
                    </script></body></html>
                ';
                break;
            default:
                throw new Exception('Invalid target');
        }
    }

    /**
     * Verify an incoming author claim for an ORCiD association.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function orcidVerify($args, $request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with orcid service');
            return;
        }

        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();
        $contextId = $context?->getId() ?? \PKP\core\PKPApplication::CONTEXT_ID_NONE;

        $templatePath = $this->plugin->getTemplateResource(self::TEMPLATE);


        $publicationId = $request->getUserVar('state');
        $authors = Repo::author()
            ->getCollector()
            ->filterByPublicationIds([$publicationId])
            ->getMany();

        $publication = Repo::publication()->get($publicationId);

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
        $templateMgr->assign([
            'currentUrl' => $request->url(null, 'index'),
            'verifySuccess' => false,
            'authFailure' => false,
            'notPublished' => false,
            'sendSubmission' => false,
            'sendSubmissionSuccess' => false,
            'denied' => false,
        ]);

        if ($authorToVerify == null) {
            // no Author exists in the database with the supplied orcidEmailToken
            $this->plugin->logError('OrcidProfileHandler::orcidverify - No author found with supplied token');
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
            Repo::author()->dao->update($authorToVerify);
            $this->plugin->logError('OrcidProfileHandler::orcidverify - ORCID access denied. Error description: ' . $request->getUserVar('error_description'));
            $templateMgr->assign('denied', true);
            $templateMgr->display($templatePath);
            return;
        }

        // fetch the access token
        $url = $this->plugin->getOrcidUrl() . OAUTH_TOKEN_URL;

        $httpClient = Application::get()->getHttpClient();
        $header = ['Accept' => 'application/json'];
        $postData = [
            'code' => $request->getUserVar('code'),
            'grant_type' => 'authorization_code',
            'client_id' => $this->plugin->getSetting($contextId, 'orcidClientId'),
            'client_secret' => $this->plugin->getSetting($contextId, 'orcidClientSecret')
        ];

        $this->plugin->logInfo('POST ' . $url);
        $this->plugin->logInfo('Request header: ' . var_export($header, true));
        $this->plugin->logInfo('Request body: ' . http_build_query($postData));
        try {
            $response = $httpClient->request(
                'POST',
                $url,
                [
                    'headers' => $header,
                    'form_params' => $postData,
                    'allow_redirects' => ['strict' => true],
                ]
            );
            if ($response->getStatusCode() != 200) {
                $this->plugin->logError('OrcidProfileHandler::orcidverify - unexpected response: ' . $response->getStatusCode());
                $templateMgr->assign('authFailure', true);
            }
            $response = json_decode($response->getBody(), true);

            $this->plugin->logInfo('Response body: ' . print_r($response, true));
            if (($response['error'] ?? null) === 'invalid_grant') {
                $this->plugin->logError('Authorization code invalid, maybe already used');
                $templateMgr->assign('authFailure', true);
            }
            if (isset($response['error'])) {
                $this->plugin->logError('Invalid ORCID response: ' . $response['error']);
                $templateMgr->assign('authFailure', true);
            }
            // Set the orcid id using the full https uri
            $orcidUri = ($this->isSandBox ? ORCID_URL_SANDBOX : ORCID_URL) . $response['orcid'];
            if (!empty($authorToVerify->getOrcid()) && $orcidUri != $authorToVerify->getOrcid()) {
                // another ORCID id is stored for the author
                $templateMgr->assign('duplicateOrcid', true);
            }
            $authorToVerify->setOrcid($orcidUri);
            if (in_array($this->plugin->getSetting($contextId, 'orcidProfileAPIPath'), [ORCID_API_URL_MEMBER_SANDBOX, ORCID_API_URL_PUBLIC_SANDBOX])) {
                // Set a flag to mark that the stored orcid id and access token came form the sandbox api
                $authorToVerify->setData('orcidSandbox', true);
                $templateMgr->assign('orcid', ORCID_URL_SANDBOX . $response['orcid']);
            } else {
                $templateMgr->assign('orcid', $orcidUri);
            }

            // remove the email token
            $authorToVerify->setData('orcidEmailToken', null);
            $this->_setOrcidData($authorToVerify, $orcidUri, $response);
            Repo::author()->dao->update($authorToVerify);
            if ($this->plugin->isMemberApiEnabled($contextId)) {
                if ($publication->getData('status') == PKPSubmission::STATUS_PUBLISHED) {
                    $templateMgr->assign('sendSubmission', true);
                    $sendResult = $this->plugin->sendSubmissionToOrcid($publication, $request);
                    if ($sendResult === true || (is_array($sendResult) && $sendResult[$response['orcid']])) {
                        $templateMgr->assign('sendSubmissionSuccess', true);
                    }
                } else {
                    $templateMgr->assign('submissionNotPublished', true);
                }
            }

            $templateMgr->assign([
                'verifySuccess' => true,
                'orcidIcon' => $this->plugin->getIcon()
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $exception) {
            $this->plugin->logInfo('Publication fail:' . $exception->getMessage());
            $templateMgr->assign('orcidAPIError', $exception->getMessage());
        }
        $templateMgr->assign('authFailure', true);
        $templateMgr->display($templatePath);
    }

    public function _setOrcidData($userOrAuthor, $orcidUri, $orcidResponse)
    {
        // Save the access token
        $orcidAccessExpiresOn = Carbon::now();
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
        return $userOrAuthor;
    }

    /**
     * Show explanation and information about ORCID
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function about($args, $request)
    {
        $context = $request->getContext();
        $contextId = $context?->getId() ?? \PKP\core\PKPApplication::CONTEXT_ID_NONE;
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('orcidIcon', $this->plugin->getIcon());
        $templateMgr->assign('isMemberApi', $this->plugin->isMemberApiEnabled($contextId));
        $templateMgr->display($this->plugin->getTemplateResource('orcidAbout.tpl'));
    }
}
