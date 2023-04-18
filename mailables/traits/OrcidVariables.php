<?php

/**
 * @file plugins/generic/orcidProfile/mailables/traits/OrcidVariables.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidVariables
 *
 * @ingroup mailables_traits
 *
 * @brief Mailable trait to set additional template variables for ORCID-related emails
 */

namespace APP\plugins\generic\orcidProfile\mailables\traits;

use APP\core\Application;
use PKP\mail\Mailable;

trait OrcidVariables
{
    protected static string $authorOrcidUrl = 'authorOrcidUrl';
    protected static string $orcidAboutUrl = 'orcidAboutUrl';

    abstract public function addData(array $data): Mailable;

    /**
     * Description of additional template variables
     */
    public static function getOrcidDataDescriptions(): array
    {
        return [
            self::$authorOrcidUrl => __('emailTemplate.variable.authorOrcidUrl'),
            self::$orcidAboutUrl => __('emailTemplate.variable.orcidAboutUrl'),
        ];
    }

    /**
     * Set values for additional email template variables
     */
    protected function setupOrcidVariables(string $oauthUrl): void
    {
        $request = Application::get()->getRequest();
        $dispatcher = Application::get()->getDispatcher();
        $this->addData([
            self::$authorOrcidUrl => $oauthUrl,
            self::$orcidAboutUrl => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'orcidapi', 'about'),
        ]);
    }
}
