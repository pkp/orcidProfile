{**
 * plugins/generic/orcidProfile/orcidProfile.tpl
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Contributed by 4Science.
 *
 * ORCID Profile authorization form
 *
 *}
<script type="text/javascript">

function openORCID() {ldelim}
	var oauthWindow = window.open("{$orcidProfileOauthPath|escape}authorize?client_id={$orcidClientId|urlencode}&response_type=code&scope=/authenticate&redirect_uri={url|urlencode page="orcidapi" op="orcidAuthorize" targetOp=$targetOp params=$params escape=false}", "_blank", "toolbar=no, scrollbars=yes, width=500, height=600, top=500, left=500");
	console.log(self);
    oauthWindow.opener = self;
	return false;
{rdelim}
</script>

<button id="connect-orcid-button" onclick="return openORCID();" {if !$params.orcidButtonVisible}style="display:none;" {/if}><img class="orcid-id-logo" src="http://orcid.org/sites/default/files/images/orcid_24x24.png" width="24" height="24" alt="{translate key='plugins.generic.orcidProfile.submitAction'}"/>{translate key='plugins.generic.orcidProfile.connect'}</button>
