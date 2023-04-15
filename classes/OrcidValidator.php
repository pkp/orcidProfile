<?php

/**
 * @file classes/OrcidValidator.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidValidator
 *
 * @brief General Orcid validations
 */

namespace APP\plugins\generic\orcidProfile\classes;

use APP\plugins\generic\orcidProfile\OrcidProfilePlugin;

class OrcidValidator
{
    /**
     * OrcidValidator constructor.
     */
    public function __construct(public OrcidProfilePlugin $plugin)
    {
    }

    /**
     * @param string $str
     */
    public function validateClientId(?string $str): bool
    {
        return (bool) preg_match('/^APP-[\da-zA-Z]{16}|(\d{4}-){3,}\d{3}[\dX]/', (string) $str);
    }

    /**
     * @param string $str
     */
    public function validateClientSecret(?string $str): bool
    {
        return (bool) preg_match('/^(\d|-|[a-f]){36,64}/', (string) $str);
    }
}
