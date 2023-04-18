<?php

/**
 * @file plugins/generic/orcidProfile/mailables/OrcidCollectAuthorId.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidCollectAuthorId
 *
 * @ingroup mailables
 *
 * @brief This email is sent to collect the ORCID id's from authors
 */

namespace APP\plugins\generic\orcidProfile\mailables;

use APP\journal\Journal;
use APP\plugins\generic\orcidProfile\mailables\traits\OrcidVariables;
use APP\submission\Submission;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class OrcidCollectAuthorId extends Mailable
{
    use Configurable;
    use Recipient;
    use OrcidVariables;

    protected static ?string $name = 'plugins.generic.orcidProfile.orcidCollectAuthorId.name';
    protected static ?string $description = 'emails.orcidCollectAuthorId.description';
    protected static ?string $emailTemplateKey = 'ORCID_COLLECT_AUTHOR_ID';
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, Submission $submission, string $oauthUrl)
    {
        parent::__construct([$context, $submission]);
        $this->setupOrcidVariables($oauthUrl);
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(
            parent::getDataDescriptions(),
            static::getOrcidDataDescriptions()
        );
    }
}
