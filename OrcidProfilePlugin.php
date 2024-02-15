<?php

/**
 * @file OrcidProfilePlugin.php
 *
 * Copyright (c) 2015-2022 University of Pittsburgh
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 *
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

namespace APP\plugins\generic\orcidProfile;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\citationStyleLanguage\CitationStyleLanguagePlugin;
use APP\publication\Publication;
use APP\author\Author;
use APP\controllers\grid\users\author\form\AuthorForm;
use APP\core\Application;
use APP\core\Request;
use APP\core\Services;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\orcidProfile\classes\form\OrcidProfileSettingsForm;
use APP\plugins\generic\orcidProfile\classes\form\OrcidProfileStatusForm;
use APP\plugins\generic\orcidProfile\classes\OrcidValidator;
use APP\plugins\generic\orcidProfile\mailables\OrcidCollectAuthorId;
use APP\plugins\generic\orcidProfile\mailables\OrcidRequestAuthorAuthorization;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Mail;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\components\forms\publication\ContributorForm;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\install\Installer;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\services\PKPSchemaService;
use PKP\submission\PKPSubmission;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewAssignment\ReviewAssignmentDAO;
use Sokil\IsoCodes\Database\Countries\Country;

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

class OrcidProfilePlugin extends GenericPlugin
{
    public const PUBID_TO_ORCID_EXT_ID = ['doi' => 'doi', 'other::urn' => 'urn'];
    public const USER_GROUP_TO_ORCID_ROLE = ['Author' => 'AUTHOR', 'Translator' => 'CHAIR_OR_TRANSLATOR', 'Journal manager' => 'AUTHOR'];

    private $currentContextId;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return true;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            $contextId = ($mainContextId === null) ? $this->getCurrentContextId() : $mainContextId;

            $validator = new OrcidValidator($this);

            $clientId = $this->getSetting($contextId, 'orcidClientId');
            $clientSecret = $this->getSetting($contextId, 'orcidClientSecret');

            if (!$validator->validateClientSecret($clientSecret) || !$validator->validateClientId($clientId)) {
                error_log(new Exception('The ORCID plugin is enabled, but its settings are invalid. In order to fix, access the plugin settings and try to save the form'));
                return $success;
            }

            Hook::add('ArticleHandler::view', [&$this, 'submissionView']);
            Hook::add('PreprintHandler::view', [&$this, 'submissionView']);

            // Insert the OrcidProfileHandler to handle ORCID redirects
            Hook::add('LoadHandler', [$this, 'setupCallbackHandler']);

            // Register callback for Smarty filters; add CSS
            Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);

            // Add "Connect ORCID" button to PublicProfileForm
            Hook::add('User::PublicProfile::AdditionalItems', [$this, 'handleUserPublicProfileDisplay']);

            // Display additional ORCID access information and checkbox to send e-mail to authors in the AuthorForm
            Hook::add('authorform::display', [$this, 'handleFormDisplay']);

            // Send email to author, if the added checkbox was ticked
            Hook::add('authorform::execute', [$this, 'handleAuthorFormExecute']);

            // Handle ORCID on user registration
            Hook::add('registrationform::execute', [$this, 'collectUserOrcidId']);

            // Send emails to authors without ORCID id upon submission
            //TODO Hook::add('submissionsubmitstep3form::execute', [$this, 'handleSubmissionSubmitStep3FormExecute']);

            // Send emails to authors without authorised ORCID access on promoting a submission to copy editing. Not included in OPS.
            if ($this->getSetting($contextId, 'sendMailToAuthorsOnPublication')) {
                Hook::add('EditorAction::recordDecision', [$this, 'handleEditorAction']);
            }

            Hook::add('Publication::publish', [$this, 'handlePublicationStatusChange']);

            Hook::add('ThankReviewerForm::thankReviewer', [$this, 'handleThankReviewer']);

            // Add more ORCiD fields to author Schema
            Hook::add('Schema::get::author', function ($hookName, $args) {
                $schema = &$args[0];

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

            // Add more ORCiD fields to user Schema
            Hook::add('Schema::get::user', function ($hookName, $args) {
                $schema = &$args[0];

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
                $schema->properties->orcidReviewPutCode = (object)[
                    'type' => 'string',
                    'apiSummary' => true,
                    'validation' => ['nullable']
                ];
            });
            Services::get('schema')->get(PKPSchemaService::SCHEMA_USER, true);

            Hook::add('Mailer::Mailables', [$this, 'addMailable']);

            Hook::add('Author::edit', [$this, 'handleAuthorFormExecute']);

            Hook::add('Form::config::before', [$this, 'addOrcidFormFields']);


            Hook::add('Installer::postInstall', [$this, 'updateSchema']);

            Hook::add('Publication::validatePublish', [$this, 'validate']);
        }

        return $success;
    }

    /**
     * Load a setting for a specific journal or load it from the config.inc.php if it is specified there.
     *
     * @param int $contextId The id of the journal from which the plugin settings should be loaded.
     * @param string $name Name of the setting.
     *
     * @return mixed          The setting value, either from the database for this context
     *                        or from the global configuration file.
     */
    public function getSetting($contextId, $name)
    {
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

        $config_value = $config_value ?? parent::getSetting($contextId, $name);
        if ($name == 'orcidProfileAPIPath') {
            if ($config_value == 'https://orcid.org/') {
                $config_value = ORCID_API_URL_PUBLIC;
            } elseif ($config_value == 'https://sandbox.orcid.org/') {
                $config_value = ORCID_API_URL_PUBLIC_SANDBOX;
            }
        }
        return $config_value;
    }

    /**
     * adds orcid  form fields.
     *
     * @param string $hookName
     * @param Form $form
     */
    public function addOrcidFormFields($hookName, $form): bool
    {
        if (!$form instanceof ContributorForm) {
            return Hook::CONTINUE;
        }

        $form->removeField('orcid');
        $form->addField(new FieldText('orcid', [
            'label' => __('user.orcid'),
            'optIntoEdit' => true,
            'optIntoEditLabel' => __('common.override'),
            'tooltip' => __('plugins.generic.orcidProfile.about.orcidExplanation'),

        ]), [FIELD_POSITION_AFTER, 'url']);

        $form->addField(new FieldOptions('requestOrcidAuthorization', [
            'label' => __('plugins.generic.orcidProfile.verify.title'),
            'options' => [
                [
                    'label' => __('plugins.generic.orcidProfile.author.requestAuthorization'),
                    'value' > false,
                ]
            ]
        ]), [FIELD_POSITION_AFTER, 'orcid']);

        $form->addField(
            new FieldOptions('deleteORCID', [
                'label' => __('plugins.generic.orcidProfile.displayName'),
                'options' => [
                    [
                        'label' => __('plugins.generic.orcidProfile.author.deleteORCID'),
                        'value' > false,
                    ]
                ],
                'showWhen' => 'orcid',
            ]),
            [FIELD_POSITION_AFTER, 'orcid']
        );

        return Hook::CONTINUE;
    }

    /**
     * @param string $hookName
     * @param array $args
     */
    public function handleThankReviewer($hookName, $args)
    {
        $request = PKPApplication::get()->getRequest();
        $context = $request->getContext();
        $newPublication = & $args[0];
        if ($this->isMemberApiEnabled($this->currentContextId)) {
            if ($this->getSetting($context->getId(), 'country') && $this->getSetting($context->getId(), 'city')) {
                $this->publishReviewerWorkToOrcid($newPublication, $request);
            }
        }
    }

    /**
     * @return bool True if the ORCID Member API has been selected in this context.
     */
    public function isMemberApiEnabled($contextId)
    {
        $apiUrl = $this->getSetting($contextId, 'orcidProfileAPIPath');
        if ($apiUrl === ORCID_API_URL_MEMBER || $apiUrl === ORCID_API_URL_MEMBER_SANDBOX) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return JSONMessage|null
     */
    public function publishReviewerWorkToOrcid(Submission $submission, Request $request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with orcid service');
            return new JSONMessage(false, __('common.sandbox'));
        }

        $context = $request->getContext();
        $requestVars = $request->getUserVars();
        /** @var ReviewAssignmentDAO */
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignmentId = $requestVars['reviewAssignmentId'];
        if (isset($reviewAssignmentId)) {
            $review = $reviewAssignmentDao->getById($reviewAssignmentId);
            $reviewer = Repo::user()->get($review->getData('reviewerId'));

            if ($reviewer->getOrcid() && $reviewer->getData('orcidAccessToken')) {
                $orcidAccessExpiresOn = Carbon::parse($reviewer->getData('orcidAccessExpiresOn'));
                if ($orcidAccessExpiresOn->isFuture()) {
                    # Extract only the ORCID from the stored ORCID uri
                    $orcid = basename(parse_url($reviewer->getOrcid(), PHP_URL_PATH));
                    $orcidReview = $this->buildOrcidReview($submission, $review, $request);
                    $uri = $this->getSetting($context->getId(), 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . $orcid . '/' . ORCID_REVIEW_URL;
                    $method = 'POST';
                    if ($putCode = $reviewer->getData('orcidReviewPutCode')) {
                        $uri .= '/' . $putCode;
                        $method = 'PUT';
                        $orcidReview['put-code'] = $putCode;
                    }
                    $headers = [
                        'Content-Type' => ' application/vnd.orcid+json; qs=4',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $reviewer->getData('orcidAccessToken')
                    ];
                    $httpClient = Application::get()->getHttpClient();

                    try {
                        $response = $httpClient->request(
                            $method,
                            $uri,
                            [
                                'headers' => $headers,
                                'json' => $orcidReview,
                                'allow_redirects' => ['strict' => true],
                            ]
                        );
                    } catch (ClientException $exception) {
                        $reason = $exception->getResponse()->getBody();
                        $this->logInfo("Publication fail: {$reason}");
                        return new JSONMessage(false);
                    }
                    $httpStatus = $response->getStatusCode();
                    $this->logInfo("Response status: {$httpStatus}");
                    $responseHeaders = $response->getHeaders();
                    switch ($httpStatus) {
                        case 200:
                            $this->logInfo("Review updated in profile, putCode: {$putCode}");
                            break;
                        case 201:
                            $location = $responseHeaders['Location'][0];
                            // Extract the ORCID work put code for updates/deletion.
                            $putCode = basename(parse_url($location, PHP_URL_PATH));
                            $reviewer->setData('orcidReviewPutCode', $putCode);
                            Repo::user()->edit($reviewer, ['orcidReviewPutCode']);
                            $this->logInfo("Review added to profile, putCode: {$putCode}");
                            break;
                        default:
                            $this->logError("Unexpected status {$httpStatus} response, body: {$responseHeaders}");
                    }
                }
            }
        }
    }

    public function buildOrcidReview($submission, $review, $request, $issue = null)
    {
        $publicationUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', $submission->getId());
        $context = $request->getContext();
        $publicationLocale = ($submission->getData('locale')) ? $submission->getData('locale') : 'en';
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $context->getId()); // DO not remove
        $supportedSubmissionLocales = $context->getSupportedSubmissionLocales();

        if (!empty($review->getData('dateCompleted')) && $context->getData('onlineIssn')) {
            $reviewCompletionDate = Carbon::parse($review->getData('dateCompleted'));

            $orcidReview = [
                'reviewer-role' => 'reviewer',
                'review-type' => 'review',
                'review-completion-date' => [
                    'year' => [
                        'value' => $reviewCompletionDate->format('Y')
                    ],
                    'month' => [
                        'value' => $reviewCompletionDate->format('m')
                    ],
                    'day' => [
                        'value' => $reviewCompletionDate->format('d')
                    ]
                ],
                'review-group-id' => 'issn:' . $context->getData('onlineIssn'),

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
            if ($review->getReviewMethod() == ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN) {
                $orcidReview['subject-url'] = ['value' => $publicationUrl];
                $orcidReview['review-url'] = ['value' => $publicationUrl];
                $orcidReview['subject-type'] = 'journal-article';
                $orcidReview['subject-name'] = [
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
            return $orcidReview;
        }
    }

    /**
     * Write info message to log.
     *
     * @param string $message Message to write
     */
    public function logInfo($message)
    {
        if ($this->getSetting($this->currentContextId, 'logLevel') === 'ERROR') {
            return;
        }
        self::writeLog($message, 'INFO');
    }

    /**
     * Write error message to log.
     *
     * @param string $message Message to write
     */
    public function logError($message)
    {
        if ($this->getSetting($this->currentContextId, 'logLevel') === 'ERROR') {
            return;
        }
        self::writeLog($message, 'ERROR');
    }

    /**
     * Write a message with specified level to log
     *
     * @param string $message Message to write
     * @param string $level Error level to add to message
     */
    private static function writeLog($message, $level)
    {
        $fineStamp = date('Y-m-d H:i:s') . substr(microtime(), 1, 4);
        error_log("{$fineStamp} {$level} {$message}\n", 3, self::logFilePath());
    }

    /**
     * @return string Path to a custom ORCID log file.
     */
    public static function logFilePath()
    {
        return Config::getVar('files', 'files_dir') . '/orcid.log';
    }

    /**
     * Hook callback: register pages for each sushi-lite method
     * This URL is of the form: orcidapi/{$orcidrequest}
     *
     * @see PKPPageRouter::route()
     */
    public function setupCallbackHandler($hookName, $params)
    {
        $page = $params[0];
        if ($this->getEnabled() && $page == 'orcidapi') {
            define('HANDLER_CLASS', OrcidProfileHandler::class);
            return true;
        }
        return false;
    }

    /**
     * Check if there exist a valid orcid configuration section in the global config.inc.php of OJS.
     *
     * @return boolean True, if the config file has api_url, client_id and client_secret set in an [orcid] section
     */
    public function isGloballyConfigured()
    {
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
     * @param string $hookName
     * @param Form[] $args
     *
     * @return bool
     *
     * @see Form::display()
     *
     */
    public function handleFormDisplay($hookName, $args)
    {
        //TODO
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        switch ($hookName) {
            case 'authorform::display':
                /** @var AuthorForm */
                $authorForm = &$args[0];
                $author = $authorForm->getAuthor();
                if ($author) {
                    $authenticated = !empty($author->getData('orcidAccessToken'));
                    $templateMgr->assign(
                        [
                            'orcidAccessToken' => $author->getData('orcidAccessToken'),
                            'orcidAccessScope' => $author->getData('orcidAccessScope'),
                            'orcidAccessExpiresOn' => $author->getData('orcidAccessExpiresOn'),
                            'orcidAccessDenied' => $author->getData('orcidAccessDenied'),
                            'orcidAuthenticated' => $authenticated
                        ]
                    );
                }

                $templateMgr->registerFilter('output', [$this, 'authorFormFilter']);
                break;
        }
        return false;
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
            $templateMgr->unregisterFilter('output', [$this, 'authorFormFilter']);
        }
        return $output;
    }

    /**
     * Hook callback: register output filter for user registration and article display.
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     *
     * @see TemplateManager::display()
     *
     */
    public function handleTemplateDisplay($hookName, $args)
    {
        //TODO orcid
        $templateMgr = &$args[0];
        $template = &$args[1];
        $request = Application::get()->getRequest();

        // Assign our private stylesheet, for front and back ends.
        $templateMgr->addStyleSheet(
            'orcidProfile',
            $request->getBaseUrl() . '/' . $this->getStyleSheet(),
            [
                'contexts' => ['frontend', 'backend']
            ]
        );

        switch ($template) {
            case 'frontend/pages/userRegister.tpl':
                $templateMgr->registerFilter('output', [$this, 'registrationFilter']);
                break;
        }
        return false;
    }

    /**
     * Return the location of the plugin's CSS file
     *
     * @return string
     */
    public function getStyleSheet()
    {
        return $this->getPluginPath() . '/css/orcidProfile.css';
    }

    public function isSandbox()
    {
        $apiUrl = $this->getSetting($this->getCurrentContextId(), 'orcidProfileAPIPath');
        return ($apiUrl == ORCID_API_URL_MEMBER_SANDBOX);
    }

    /**
     * Output filter adds ORCiD interaction to registration form.
     *
     * @param string $output
     * @param TemplateManager $templateMgr
     *
     * @return string
     */
    public function registrationFilter($output, $templateMgr)
    {
        if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
            $match = $matches[0][0];
            $offset = $matches[0][1];
            $targetOp = 'register';
            $templateMgr->assign([
                'targetOp' => $targetOp,
                'orcidUrl' => $this->getOrcidUrl(),
                'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', ['targetOp' => $targetOp]),
                'orcidIcon' => $this->getIcon(),
            ]);

            $newOutput = substr($output, 0, $offset + strlen($match));
            $newOutput .= $templateMgr->fetch($this->getTemplateResource('orcidProfile.tpl'));
            $newOutput .= substr($output, $offset + strlen($match));
            $output = $newOutput;
            $templateMgr->unregisterFilter('output', [$this, 'registrationFilter']);
        }
        return $output;
    }

    /**
     * Return the ORCID website url (prod or sandbox) based on the current API configuration
     *
     * @return string
     */
    public function getOrcidUrl()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = ($context == null) ? 0 : $context->getId();

        $apiPath = $this->getSetting($contextId, 'orcidProfileAPIPath');
        return in_array($apiPath, [ORCID_API_URL_PUBLIC, ORCID_API_URL_MEMBER]) ? ORCID_URL : ORCID_URL_SANDBOX;
    }

    /**
     * Return an ORCID OAuth authorization link with
     *
     * @param string $handlerMethod containting a valid method of the OrcidProfileHandler
     * @param array $redirectParams associative array with additional request parameters for the redirect URL
     */
    public function buildOAuthUrl($handlerMethod, $redirectParams)
    {
        $request = Application::get()->getRequest();
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
        $redirectUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            'orcidapi',
            $handlerMethod,
            null,
            $redirectParams
        );

        return $this->getOauthPath() . 'authorize?' . http_build_query(
            [
                'client_id' => $this->getSetting($contextId, 'orcidClientId'),
                'response_type' => 'code',
                'scope' => $scope,
                'redirect_uri' => $redirectUrl]
        );
    }

    /**
     * Return the OAUTH path (prod or sandbox) based on the current API configuration
     *
     * @return string
     */
    public function getOauthPath()
    {
        return $this->getOrcidUrl() . 'oauth/';
    }

    /**
     * Return a string of the ORCiD SVG icon
     *
     * @return string
     */
    public function getIcon()
    {
        $path = Core::getBaseDir() . '/' . $this->getPluginPath() . '/templates/images/orcid.svg';
        return file_exists($path) ? file_get_contents($path) : '';
    }

    /**
     * Renders additional content for the PublicProfileForm.
     *
     * Called by @param string $output
     *
     *
     * @return bool
     *
     * @see lib/pkp/templates/user/publicProfileForm.tpl
     *
     */
    public function handleUserPublicProfileDisplay($hookName, $params)
    {
        $templateMgr = &$params[1];
        $output = &$params[2];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $userId = $request->getUser()->getId();
        $user = Repo::user()->get($userId);
        $contextId = ($context == null) ? 0 : $context->getId();
        $targetOp = 'profile';
        $templateMgr->assign(
            [
                'targetOp' => $targetOp,
                'orcidUrl' => $this->getOrcidUrl(),
                'orcidOAuthUrl' => $this->buildOAuthUrl('orcidAuthorize', ['targetOp' => $targetOp]),
                'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
                'orcidIcon' => $this->getIcon(),
                'orcidAuthenticated' => !empty($user->getData('orcidAccessToken')),
            ]
        );

        $output = $templateMgr->fetch($this->getTemplateResource('orcidProfile.tpl'));
        return true;
    }

    /**
     * handleAuthorFormExecute sends an e-mail to the author if a specific checkbox was ticked in the author form.
     *
     * @param string $hookname
     * @param AuthorForm[] $args
     *
     * @see AuthorForm::execute() The function calling the hook.
     *
     */
    public function handleAuthorFormExecute($hookname, $args)
    {
        if (count($args) == 3) {
            /** @var Author */
            $author = &$args[0];
            $values = $args[2];

            if ($author && $values['requestOrcidAuthorization']) {
                $this->sendAuthorMail($author);
            }

            if ($author && $values['deleteORCID']) {
                $author->setOrcid(null);
                $this->removeOrcidAccessToken($author, false);
            }
        }
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
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        // This should only ever happen within a context, never site-wide.
        if ($context != null) {
            $contextId = $context->getId();
            $publicationId = $author->getData('publicationId');
            $publication = Repo::publication()->get($publicationId);
            $submission = Repo::submission()->get($publication->getData('submissionId'));

            $emailToken = md5(microtime() . $author->getEmail());
            $author->setData('orcidEmailToken', $emailToken);
            $oauthUrl = $this->buildOAuthUrl('orcidVerify', ['token' => $emailToken, 'state' => $publicationId]);

            if ($this->isMemberApiEnabled($contextId)) {
                $mailable = new OrcidRequestAuthorAuthorization($context, $submission, $oauthUrl);
            } else {
                $mailable = new OrcidCollectAuthorId($context, $submission, $oauthUrl);
            }

            // Set From to primary journal contact
            $mailable->from($context->getData('contactEmail'), $context->getData('contactName'));

            // Send to author
            $mailable->recipients([$author]);
            $emailTemplateKey = $mailable::getEmailTemplateKey();
            $emailTemplate = Repo::emailTemplate()->getByKey($contextId, $emailTemplateKey);
            $mailable->body($emailTemplate->getLocalizedData('body'))
                ->subject($emailTemplate->getLocalizedData('subject'));
            Mail::send($mailable);

            if ($updateAuthor) {
                Repo::author()->dao->update($author);
            }
        }
    }

    /**
     * Remove all data fields, which belong to an ORCID access token from the
     * given Author object. Also updates fields in the db.
     *
     * @param Author $author object with ORCID access token
     */
    public function removeOrcidAccessToken($author, $saveAuthor = true)
    {
        $author->setData('orcidAccessToken', null);
        $author->setData('orcidAccessScope', null);
        $author->setData('orcidRefreshToken', null);
        $author->setData('orcidAccessExpiresOn', null);
        $author->setData('orcidSandbox', null);

        if ($saveAuthor) {
            Repo::author()->dao->update($author);
        }
    }

    /**
     * Collect the ORCID when registering a user.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool
     */
    public function collectUserOrcidId($hookName, $params)
    {
        $form = $params[0];
        $user = $form->user;

        $form->readUserVars(['orcid']);
        $user->setOrcid($form->getData('orcid'));
        return false;
    }

    /**
     * Output filter adds ORCiD interaction to the 3rd step submission form.
     *
     *
     * @return bool
     */
    public function handleSubmissionSubmitStep3FormExecute($hookName, $params)
    {
        $form = $params[0];
        // Have to use global Request access because request is not passed to hook
        $publication = Repo::publication()->get($form->submission->getData('currentPublicationId'));
        $authors = $publication->getData('authors');

        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $author = $authors->first();
        //error_log("OrcidProfilePlugin: authors[0] = " . var_export($authors[0], true));
        //error_log("OrcidProfilePlugin: user = " . var_export($user, true));
        if ($author?->getOrcid() === $user->getOrcid()) {
            // if the author and user share the same ORCID id
            // copy the access token from the user
            //error_log("OrcidProfilePlugin: user->orcidAccessToken = " . $user->getData('orcidAccessToken'));
            $author->setData('orcidAccessToken', $user->getData('orcidAccessToken'));
            $author->setData('orcidAccessScope', $user->getData('orcidAccessScope'));
            $author->setData('orcidRefreshToken', $user->getData('orcidRefreshToken'));
            $author->setData('orcidAccessExpiresOn', $user->getData('orcidAccessExpiresOn'));
            $author->setData('orcidSandbox', $user->getData('orcidSandbox'));

            Repo::author()->dao->update($author);

            //error_log("OrcidProfilePlugin: author = " . var_export($authors[0], true));
        }
        return false;
    }

    /**
     * Add additional ORCID specific fields to the Author and User objects
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool
     */
    public function handleAdditionalFieldNames($hookName, $params)
    {
        $fields = &$params[1];
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
    public function getDescription()
    {
        return __('plugins.generic.orcidProfile.description');
    }

    /**
     * @see PKPPlugin::getInstallEmailTemplatesFile()
     */
    public function getInstallEmailTemplatesFile()
    {
        return ($this->getPluginPath() . '/emailTemplates.xml');
    }

    /**
     * Extend the {url ...} smarty to support this plugin.
     */
    public function smartyPluginUrl($params, $smarty)
    {
        $path = [$this->getCategory(), $this->getName()];
        if (is_array($params['path'])) {
            $params['path'] = array_merge($path, $params['path']);
        } elseif (!empty($params['path'])) {
            $params['path'] = array_merge($path, [$params['path']]);
        } else {
            $params['path'] = $path;
        }

        if (!empty($params['id'])) {
            $params['path'] = array_merge($params['path'], [$params['id']]);
            unset($params['id']);
        }
        return $smarty->smartyUrl($params, $smarty);
    }

    public function submissionView($hookName, $args)
    {
        $request = $args[0];
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign(['orcidIcon' => $this->getIcon()]);
    }

    /**
     * @see Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url(
                            $request,
                            null,
                            null,
                            'manage',
                            null,
                            [
                                'verb' => 'settings',
                                'plugin' => $this->getName(),
                                'category' => 'generic'
                            ]
                        ),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
                new LinkAction(
                    'status',
                    new AjaxModal($router->url($request, null, null, 'manage', null, ['verb' => 'status', 'plugin' => $this->getName(), 'category' => 'generic']), $this->getDisplayName()),
                    __('common.status'),
                    null
                )
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @see Plugin::manage()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.orcidProfile.displayName');
    }

    public function manage($args, $request)
    {
        $context = $request->getContext();
        $contextId = ($context == null) ? 0 : $context->getId();

        switch ($request->getUserVar('verb')) {
            case 'settings':

                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

                $templateMgr->assign('orcidApiUrls', [
                    ORCID_API_URL_PUBLIC => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.public',
                    ORCID_API_URL_PUBLIC_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.publicSandbox',
                    ORCID_API_URL_MEMBER => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.member',
                    ORCID_API_URL_MEMBER_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.memberSandbox'
                ]);

                $countries = collect(Locale::getCountries())
                    ->mapWithKeys(fn (Country $country) => [$country->getAlpha2() => $country->getLocalName()])
                    ->sort(fn (string $a, string $b) => strcoll($a, $b))
                    ->toArray();

                $templateMgr->assign('countries', $countries);
                $templateMgr->assign('logLevelOptions', [
                    'ERROR' => 'plugins.generic.orcidProfile.manager.settings.logLevel.error',
                    'ALL' => 'plugins.generic.orcidProfile.manager.settings.logLevel.all'
                ]);

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
     * @param string $hookName
     * @param Issue[] $args Issue object that will be published
     *
     **@see
     *
     */
    public function handlePublicationStatusChange($hookName, $args)
    {
        /** @var Publication $newPublication */
        $newPublication = &$args[0];

        $request = Application::get()->getRequest();

        switch ($newPublication->getData('status')) {
            case PKPSubmission::STATUS_PUBLISHED:
                $this->sendSubmissionToOrcid($newPublication, $request);
                break;
            case PKPSubmission::STATUS_SCHEDULED:
                $this->sendSubmissionToOrcid($newPublication, $request);
                break;
        }
    }

    /**
     * sendSubmissionToOrcid posts JSON consisting of submission, journal and issue meta data
     * to ORCID profiles of submission authors.
     *
     * @see https://github.com/ORCID/ORCID-Source/tree/master/orcid-model/src/main/resources/record_2.1
     * for documentation and examples of the ORCID JSON format.
     *
     * @param Publication $publication Publication for which the data will be sent to ORCID
     *
     * @return bool|bool[]|JSONMessage
     *
     **/
    public function sendSubmissionToOrcid($publication, $request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with orcid service');
            return new JSONMessage(false, __('common.sandbox'));
        }
        
        $context = $request->getContext();
        $contextId = $this->currentContextId = $context->getId();
        $publicationId = $publication->getId();
        $submissionId = $publication->getData('submissionId');

        if (!$this->isMemberApiEnabled($contextId)) {
            // Sending to ORCID only works with the member API
            return false;
        }

        $issueId = $publication->getData('issueId');
        if (isset($issueId)) {
            $issue = Repo::issue()->get($issueId);
        }

        $authors = Repo::author()
            ->getCollector()
            ->filterByPublicationIds([$publicationId])
            ->getMany();

        // Collect valid ORCID ids and access tokens from submission contributors
        $authorsWithOrcid = [];
        foreach ($authors as $author) {
            if ($author->getOrcid() && $author->getData('orcidAccessToken')) {
                $orcidAccessExpiresOn = Carbon::parse($author->getData('orcidAccessExpiresOn'));
                if ($orcidAccessExpiresOn->isFuture()) {
                    # Extract only the ORCID from the stored ORCID uri
                    $orcid = basename(parse_url($author->getOrcid(), PHP_URL_PATH));
                    $authorsWithOrcid[$orcid] = $author;
                } else {
                    $this->logError("Token expired on {$orcidAccessExpiresOn} for author " . $author->getId() . ', deleting orcidAccessToken!');
                    $this->removeOrcidAccessToken($author);
                }
            }
        }

        if (empty($authorsWithOrcid)) {
            $this->logInfo('No contributor with ORICD id or valid access token for submission ' . $submissionId);
            return false;
        }

        $orcidWork = $this->buildOrcidWork($publication, $context, $authors->toArray(), $request, $issue);
        $this->logInfo('Request body (without put-code): ' . json_encode($orcidWork));

        $requestsSuccess = [];
        foreach ($authorsWithOrcid as $orcid => $author) {
            $uri = $this->getSetting($contextId, 'orcidProfileAPIPath') . ORCID_API_VERSION_URL . $orcid . '/' . ORCID_WORK_URL;
            $method = 'POST';

            if ($putCode = $author->getData('orcidWorkPutCode')) {
                // Submission has already been sent to ORCID. Use PUT to update meta data
                $uri .= '/' . $putCode;
                $method = 'PUT';
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

            $this->logInfo("{$method} {$uri}");
            $this->logInfo('Header: ' . var_export($headers, true));

            $httpClient = Application::get()->getHttpClient();
            try {
                $response = $httpClient->request(
                    $method,
                    $uri,
                    [
                        'headers' => $headers,
                        'json' => $orcidWork,
                        'allow_redirects' => ['strict' => true],
                    ]
                );
            } catch (ClientException $exception) {
                $reason = $exception->getResponse()->getBody();
                $this->logInfo("Publication fail: {$reason}");
                return new JSONMessage(false);
            }
            $httpstatus = $response->getStatusCode();
            $this->logInfo("Response status: {$httpstatus}");
            $responseHeaders = $response->getHeaders();

            switch ($httpstatus) {
                case 200:
                    // Work updated
                    $this->logInfo("Work updated in profile, putCode: {$putCode}");
                    $requestsSuccess[$orcid] = true;
                    break;
                case 201:
                    $location = $responseHeaders['Location'][0];
                    // Extract the ORCID work put code for updates/deletion.
                    $putCode = intval(basename(parse_url($location, PHP_URL_PATH)));
                    $this->logInfo("Work added to profile, putCode: {$putCode}");
                    $author->setData('orcidWorkPutCode', $putCode);
                    Repo::author()->dao->update($author);
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
                        $this->logError('Work deleted from ORCID record, deleting putCode form author');
                        $author->setData('orcidWorkPutCode', null);
                        Repo::author()->dao->update($author);
                        $requestsSuccess[$orcid] = false;
                    } else {
                        $this->logError("Unexpected status {$httpstatus} response, body: " . $response->getBody());
                        $requestsSuccess[$orcid] = false;
                    }
                    break;
                case 409:
                    $this->logError('Work already added to profile, response body: ' . $response->getBody());
                    $requestsSuccess[$orcid] = false;
                    break;
                default:
                    $this->logError("Unexpected status {$httpstatus} response, body: " . $response->getBody());
                    $requestsSuccess[$orcid] = false;
            }
        }
        return array_product($requestsSuccess) ? true : $requestsSuccess;
    }

    /**
     * Build an associative array with submission meta data, which can be encoded to a valid ORCID work JSON structure.
     *
     * @see https://github.com/ORCID/ORCID-Source/blob/master/orcid-model/src/main/resources/record_2.1/samples/write_sample/bulk-work-2.1.json
     *  Example of valid ORCID JSON for adding works to an ORCID record.
     *
     * @param Publication $publication extract data from this Article
     * @param Journal $context Context object the Submission is part of
     * @param Author[] $authors Array of Author objects, the contributors of the publication
     * @param Issue $issue Issue the Article is part of
     * @param Request $request the current request
     *
     * @return array             an associative array with article meta data corresponding to ORCID work JSON structure
     */
    public function buildOrcidWork($publication, $context, $authors, $request, $issue = null)
    {
        $submission = Repo::submission()->get($publication->getData('submissionId'));

        $applicationName = Application::get()->getName();
        $bibtexCitation = '';

        $publicationLocale = ($publication->getData('locale')) ? $publication->getData('locale') : 'en';
        $supportedSubmissionLocales = $context->getSupportedSubmissionLocales();

        $publicationUrl = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'article', 'view', $submission->getId());

        $orcidWork = [
            'title' => [
                'title' => [
                    'value' => trim(strip_tags($publication->getLocalizedTitle($publicationLocale))) ?? ''
                ],
                'subtitle' => [
                    'value' => trim(strip_tags($publication->getLocalizedData('subtitle', $publicationLocale))) ?? ''
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
                'citation-value' => $bibtexCitation,
            ];
            $orcidWork['type'] = 'journal-article';
        } elseif ($applicationName == 'ops') {
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
     * Build the external identifiers ORCID JSON structure from article, journal and issue meta data.
     *
     * @see  https://pub.orcid.org/v2.0/identifiers Table of valid ORCID identifier types.
     *
     * @param Submission $submission The Article object for which the external identifiers should be build.
     * @param Publication $publication The Article object for which the external identifiers should be build.
     * @param Journal $context Context the Submission is part of.
     * @param Issue $issue The Issue object the Article object belongs to.
     *
     * @return array            An associative array corresponding to ORCID external-id JSON.
     */
    private function buildOrcidExternalIds($submission, $publication, $context, $issue, $articleUrl)
    {
        $contextId = $context->getId();

        $externalIds = [];
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $contextId);
        // Add doi, urn, etc. for article
        $articleHasStoredPubId = false;
        if (is_array($pubIdPlugins) || $context->areDoisEnabled()) {
            // Handle non-DOI pubIds
            if (is_array($pubIdPlugins)) {
                foreach ($pubIdPlugins as $plugin) {
                    if (!$plugin->getEnabled()) {
                        continue;
                    }

                    $pubIdType = $plugin->getPubIdType();

                    # Add article ids
                    $pubId = $publication->getStoredPubId($pubIdType);

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

                // Handle DOIs
                if ($context->areDoisEnabled()) {
                    # Add article ids
                    $doiObject = $publication->getData('doiObject');

                    if ($doiObject) {
                        $externalIds[] = [
                            'external-id-type' => self::PUBID_TO_ORCID_EXT_ID['doi'],
                            'external-id-value' => $doiObject->getData('doi'),
                            'external-id-url' => [
                                'value' => $doiObject->getResolvingUrl()
                            ],
                            'external-id-relationship' => 'self'
                        ];

                        $articleHasStoredPubId = true;
                    }
                }

                # Add issue ids if they exist
                if ($issue) {
                    $doiObject = $issue->getData('doiObject');
                    if ($doiObject) {
                        $externalIds[] = [
                            'external-id-type' => self::PUBID_TO_ORCID_EXT_ID['doi'],
                            'external-id-value' => $doiObject->getData('doi'),
                            'external-id-url' => [
                                'value' => $doiObject->getResolvingUrl()
                            ],
                            'external-id-relationship' => 'part-of'
                        ];
                    }
                }
            }
        } else {
            error_log('OrcidProfilePlugin::buildOrcidExternalIds: No pubId plugins could be loaded');
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
     * @param null|mixed $issue
     *
     * @return array Associative array with year, month and day or only year
     */
    private function buildOrcidPublicationDate($publication, $issue = null)
    {
        $publicationPublishDate = Carbon::parse($publication->getData('datePublished'));

        return [
            'year' => ['value' => $publicationPublishDate->format('Y')],
            'month' => ['value' => $publicationPublishDate->format('m')],
            'day' => ['value' => $publicationPublishDate->format('d')]
        ];
    }

    /**
     * Build associative array fitting for ORCID contributor mentions in an
     * ORCID work from the supplied Authors array.
     *
     * @param Author[] $authors Array of Author objects
     *
     * @return array[]           Array of associative arrays,
     *                           one for each contributor
     */
    private function buildOrcidContributors($authors, $context, $publication)
    {
        $contributors = [];
        $first = true;

        foreach ($authors as $author) {
            // TODO Check if e-mail address should be added
            $fullName = $author->getLocalizedGivenName() . ' ' . $author->getLocalizedFamilyName();

            if (strlen($fullName) == 0) {
                $this->logError('Contributor Name not defined' . $author->getAllData());
            }
            $contributor = [
                'credit-name' => $fullName,
                'contributor-attributes' => [
                    'contributor-sequence' => $first ? 'first' : 'additional'
                ]
            ];

            $userGroup = $author->getUserGroup();
            $role = self::USER_GROUP_TO_ORCID_ROLE[$userGroup->getName('en')];

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
     * handleEditorAction handles promoting a submission to copyediting.
     *
     * @param string $hookName Name the hook was registered with
     * @param array $args Hook arguments, &$submission, &$editorDecision, &$result, &$recommendation.
     *
     * @see EditorAction::recordDecision() The function calling the hook.
     */
    public function handleEditorAction($hookName, $args)
    {
        $submission = $args[0];
        /** @var Submission $submission */
        $decision = $args[1];

        if ($decision['decision'] == Decision::ACCEPT) {
            $publication = $submission->getCurrentPublication();

            if (isset($publication)) {
                $authors = Repo::author()->getCollector()
                    ->filterByPublicationIds([$submission->getCurrentPublication()->getId()])
                    ->getMany();

                foreach ($authors as $author) {
                    $orcidAccessExpiresOn = Carbon::parse($author->getData('orcidAccessExpiresOn'));
                    if ($author->getData('orcidAccessToken') == null || $orcidAccessExpiresOn->isPast()) {
                        $this->sendAuthorMail($author, true);
                    }
                }
            }
        }
    }

    /**
     * Set the current id of the context (atm only considered for logging settings).
     *
     * @param int $contextId the Id of the currently active context (journal)
     */
    public function setCurrentContextId($contextId)
    {
        $this->currentContextId = $contextId;
    }

    /**
     * Add mailable to the list of mailables in the application
     */
    public function addMailable(string $hookName, array $args): void
    {
        foreach ([OrcidCollectAuthorId::class, OrcidRequestAuthorAuthorization::class] as $mailable) {
            $args[0]->push($mailable);
        }
    }

    /**
     * @copydoc Plugin::updateSchema()
     */
    public function updateSchema($hookName, $args)
    {
        $installer = $args[0];
        $result = &$args[1];
        $migration = new OrcidProfileEmailDataMigration($installer, $this);
        try {
            $migration->up();
        } catch (Exception $e) {
            $installer->setError(Installer::INSTALLER_ERROR_DB, __('installer.installMigrationError', ['class' => get_class($migration), 'message' => $e->getMessage()]));
            $result = false;
        }
    }

    /**
     * Pre-publication checks
     *
     * @return false
     */
    public function validate($hookName, $args)
    {
        $errors = & $args[0];
        $publication = $args[1];
        $orcidIds = [];
        foreach ($publication->getData('authors') as $author) {
            $authorOrcid = $author->getData('orcid');
            if ($authorOrcid and in_array($authorOrcid, $orcidIds)) {
                $errors['hasDuplicateOrcids'] = __('plugins.generic.orcidProfile.verify.duplicateOrcidAuthor');
            } elseif ($authorOrcid && !$author->getData('orcidAccessToken')) {
                $errors['hasUnauthenticatedOrcid'] = __('plugins.generic.orcidProfile.verify.hasUnauthenticatedOrcid');
            } else {
                $orcidIds[] = $authorOrcid;
            }
        }

        return false;
    }
}
