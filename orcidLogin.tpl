{**
 * plugins/generic/orcidProfile/orcidProfile.tpl
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * ORCID Profile authorization form
 *
 *}

{literal}
	<style>
		#orcidLoginLink {
			margin: 15px;
		}
	</style>
{/literal}
<div id='orcidLoginLink'>
	<a href="{$orcidProfileOauthPath|escape}authorize?client_id={$orcidClientId|urlencode}&response_type=code&scope=/authenticate&redirect_uri={url|urlencode page="orcidapi" op="orcidAuthorize" targetOp=$targetOp params=$params escape=false}">
		<img id="orcid-id-logo" src="http://orcid.org/sites/default/files/images/orcid_16x16.png" width='16' height='16' alt="ORCID logo" alt="{translate key='plugins.generic.orcidProfile.submitAction'}"/>
		{translate key='plugins.generic.orcidProfile.linkMessage'}
	</a>
</div>