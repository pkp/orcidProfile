# ORCID Profile Puller

**NOTE: This plugin is a work in progress and not yet ready for production use.**

**NOTE: Please ensure you're using the correct branch. Use the [master branch](https://github.com/asmecher/orcidProfile/tree/master) for OJS 3.0, and the [ojs-dev-2_4 branch](https://github.com/asmecher/orcidProfile/tree/ojs-dev-2_4) for OJS 2.4.x.**

Plugin for PKP user profiles (tested with OJS 2.x)

Copyright © 2015-2016 University of Pittsburgh
<br />Copyright © 2014-2017 Simon Fraser University Library
<br />Copyright © 2003-2017 John Willinsky

Licensed under GPL 2 or better.

Contributed by 4Science (http://www.4science.it).

## Features:

 * Hooks into the User Profile, Registration, and Submission (step 3) forms
 * Searchs for co-authors ORCIDs
 * Requests ORCIDs of authors via email

## Install:

 * Copy the source into the PKP product's plugins/generic folder.
 * Run `tools/upgrade.php upgrade` to allow the system to recognize the new plugin.
 * Enable this plugin within the administration interface.
 * Set up [an application with ORCID](https://orcid.org/developer-tools).
  * The URI and description are to reassure users.
  * The redirect URI can be anything within your OJS/OMP installation.
  * ORCID will give you a client ID and secret.
 * Consider the settings within the administation interface.
  * Enter the client and secret from the ORCID application setup.
