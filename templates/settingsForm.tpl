{**
 * plugins/generic/orcidProfile/settingsForm.tpl
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * ORCID Profile plugin settings
 *
 *}
{strip}
{assign var="pageTitle" value="plugins.generic.orcidProfile.manager.orcidProfileSettings"}
{include file="common/header.tpl"}
{/strip}
<div id="orcidProfileSettings">
<div id="description">{translate key="plugins.generic.orcidProfile.manager.settings.description"}</div>

<div class="separator"></div>

<form method="post" action="{plugin_url path="settings"}">
{include file="common/formErrors.tpl"}

<table width="100%" class="data">
	<tr valign="top">
		<td width="20%" class="label">{fieldLabel name="orcidProfileAPIPath" required="true" key="plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath"}</td>
		<td width="80%" class="value">{html_options_translate name="orcidProfileAPIPath" options=$orcidApiUrls selected=$orcidProfileAPIPath}</td>
	</tr>
	<tr valign="top">
		<td class="label">{fieldLabel name="orcidClientId" required="true" key="plugins.generic.orcidProfile.manager.settings.orcidClientId"}</td>
		<td class="label"><input type="text" name="orcidClientId" id="orcidClientId" value="{$orcidClientId|escape}" size="40" class="textField" /></td>
	</tr>
	<tr valign="top">
		<td class="label">{fieldLabel name="orcidClientSecret" required="true" key="plugins.generic.orcidProfile.manager.settings.orcidClientSecret"}</td>
		<td class="label"><input type="text" name="orcidClientSecret" id="orcidClientSecret" value="{$orcidClientSecret|escape}" size="40" class="textField" /></td>
	</tr>
</table>

<input type="submit" name="save" class="button defaultButton" value="{translate key="common.save"}"/><input type="button" class="button" value="{translate key="common.cancel"}" onclick="history.go(-1)"/>
</form>

<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</div>
{include file="common/footer.tpl"}
