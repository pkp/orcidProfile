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

function openSearchORCID() {ldelim}
	var oauthWindow = window.open("{url|escape:"javascript" page="orcidapi" op="orcidSearch" targetOp="form" orcidInputId=$params.orcidInputId orcidButtonId=$params.orcidButtonId authorIndex=$params.authorIndex escape=false}", "_blank", "location=no, titlebar=no, status=no, menubar=no, toolbar=yes, scrollbars=yes, width=500, height=400, top=200, left=500");
	oauthWindow.opener = self;
	return false;
{rdelim}
</script>

<button id="{$params.orcidButtonId}" onclick="return openSearchORCID();" {if !$params.orcidButtonVisible}style="display:none;" {/if}><img class="orcid-id-logo" src="http://orcid.org/sites/default/files/images/orcid_24x24.png" width="24" height="24" alt="{translate key='plugins.generic.orcidProfile.submitAction'}"/>Search the ORCID iD</button>
