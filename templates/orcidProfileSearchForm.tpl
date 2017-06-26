{**
 * plugins/generic/orcidProfile/orcidProfileSearchForm.tpl
 *
 * Copyright (c) 2015-2017 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Contributed by 4Science (http://www.4science.it).
 *
 * ORCID Profile search form
 *
 *}

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
<head>
	<title>{translate key='plugins.generic.orcidProfile.searchPageTitle'}</title>
	<meta http-equiv="Content-Type" content="text/html; charset={$defaultCharset|escape}" />
	<meta name="description" content="" />
	<meta name="keywords" content="" />

	{if $displayFavicon}<link rel="icon" href="{$faviconDir}/{$displayFavicon.uploadName|escape:"url"}" type="{$displayFavicon.mimeType|escape}" />{/if}

	<link rel="stylesheet" href="{$baseUrl}/lib/pkp/styles/common.css" type="text/css" />
	<link rel="stylesheet" href="{$baseUrl}/styles/common.css" type="text/css" />
	<link rel="stylesheet" href="{$baseUrl}/styles/compiled.css" type="text/css" />
	<link rel="stylesheet" href="{$baseUrl}/styles/comments.css" type="text/css" />

	{foreach from=$stylesheets item=cssUrl}
		<link rel="stylesheet" href="{$cssUrl}" type="text/css" />
	{/foreach}

	<!-- Base Jquery -->
	{if $allowCDN}<script type="text/javascript" src="//www.google.com/jsapi"></script>
		<script type="text/javascript">{literal}
		// Provide a local fallback if the CDN cannot be reached
		if (typeof google == 'undefined') {
			document.write(unescape("%3Cscript src='{/literal}{$baseUrl}{literal}/lib/pkp/js/lib/jquery/jquery.min.js' type='text/javascript'%3E%3C/script%3E"));
			document.write(unescape("%3Cscript src='{/literal}{$baseUrl}{literal}/lib/pkp/js/lib/jquery/plugins/jqueryUi.min.js' type='text/javascript'%3E%3C/script%3E"));
		} else {
			google.load("jquery", "{/literal}{$smarty.const.CDN_JQUERY_VERSION}{literal}");
			google.load("jqueryui", "{/literal}{$smarty.const.CDN_JQUERY_UI_VERSION}{literal}");
		}
		{/literal}</script>
	{else}
		<script type="text/javascript" src="{$baseUrl}/lib/pkp/js/lib/jquery/jquery.min.js"></script>
		<script type="text/javascript" src="{$baseUrl}/lib/pkp/js/lib/jquery/plugins/jqueryUi.min.js"></script>
	{/if}

	<!-- Compiled scripts -->
	{if $useMinifiedJavaScript}
		<script type="text/javascript" src="{$baseUrl}/js/pkp.min.js"></script>
	{else}
		{include file="common/minifiedScripts.tpl"}
	{/if}

	{$additionalHeadData}
</head>

{literal}
	<script type="text/javascript">
		$(document).ready(function(){
			$("#searchOrcidName").val('');
			$("#searchOrcidLastname").val('');
			$("#search-orcid-email").val('');
		});
	</script>
{/literal}

<h3>{translate key='plugins.generic.orcidProfile.searchPageTitle'}</h3>
<div id="content">
	<form method="post" id="orcidSearchForm" action="{url|escape page="orcidapi" op="orcidSearch" targetOp="search" orcidInputId=$orcidInputId orcidButtonId=$orcidButtonId authorIndex=$authorIndex profilesPage=1 escape=false}">
		<table width="100%" class="data">

			<tr valign="top">
				<td width="20%" class="label">{translate key='user.firstName'}</td>
				<td width="80%" class="value"><input type="text" class="textField" name="searchOrcidName" id="searchOrcidName" value="" size="30" maxlength="100" /></td>
			</tr>
			<tr valign="top">
				<td>&nbsp;</td>
				<td>&nbsp;</td>
			</tr>
			<tr valign="top">
				<td width="20%" class="label">{translate key='user.lastName'}</td>
				<td width="80%" class="value"><input type="text" class="textField" name="searchOrcidLastname" id="searchOrcidLastname" value="" size="30" maxlength="100" /></td>
			</tr>
			<tr valign="top">
				<td>&nbsp;</td>
				<td>&nbsp;</td>
			</tr>
			<tr valign="top">
				<td>&nbsp;</td>
				<td class="label">{translate key='plugins.generic.orcidProfile.searchAndOr'}</td>
			</tr>
			<tr valign="top">
				<td>&nbsp;</td>
				<td>&nbsp;</td>
			</tr>
			<tr valign="top">
				<td width="20%" class="label">{translate key='user.email'}</td>
				<td width="80%" class="value"><input type="text" class="textField" name="searchOrcidEmail" id="searchOrcidEmail" value="" size="30" maxlength="100" /></td>
			</tr>

			<tr valign="top">
				<td width="20%" class="label"></td>
				<td width="80%" class="value"><button id="search-orcid-button" type="submit"><img class="orcid-id-logo" src="http://orcid.org/sites/default/files/images/orcid_24x24.png" width="24" height="24" alt="{translate key='plugins.generic.orcidProfile.submitAction'}"/>{translate key='plugins.generic.orcidProfile.searchOrcidId'}</button></td>
			</tr>

		</table>
	</form>
</div>
