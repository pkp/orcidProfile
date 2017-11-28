{**
 * plugins/generic/orcidProfile/orcidProfile.tpl
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * ORCID Profile authorization form
 *
 *}
<script type="text/javascript">
function openORCID() {ldelim}
	var oauthWindow = window.open("{$orcidProfileOauthPath|escape}authorize?client_id={$orcidClientId|urlencode}&response_type=code&scope=/authenticate&redirect_uri={url|urlencode router="page" page="orcidapi" op="orcidAuthorize" targetOp=$targetOp params=$params escape=false}", "_blank", "toolbar=no, scrollbars=yes, width=500, height=600, top=500, left=500");
	oauthWindow.opener = self;
	return false;
{rdelim}
</script>

<button id="connect-orcid-button" class="cmp_button" onclick="return openORCID();">
	{$orcidIcon}
	{translate key='plugins.generic.orcidProfile.connect'}
</button>

{if $targetOp eq 'register'}
	{fbvElement type="hidden" name="orcid" id="orcid" value=$orcid maxlength="37"}
{/if}
