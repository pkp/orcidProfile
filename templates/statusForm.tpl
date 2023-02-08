{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2015-2019 University of Pittsburgh
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * ORCID Profile plugin settings
 *
 *}

<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#orcidProfileStatusForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>


<div id="orcidProfileStatus">
	<h3>{translate key="plugins.generic.orcidProfile.manager.status.description"}</h3>

	<div class="description">
	{if $globallyConfigured}
		{translate key="plugins.generic.orcidProfile.manager.status.configuration.global"}
	{else}
		{translate key="plugins.generic.orcidProfile.manager.status.configuration.journal"}
	{/if}
	</div>
	<div class="description">
	{if $pluginEnabled}
		<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.enabled"}</p>
	{else}
		<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.disabled"}</p>
	</div>

	{/if}
   </div>


<div class="description">
	{if $clientIdValid}
	<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.clientIdValid"}</p>
	{else}
	<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.clientIdInvalid"}</p>
	{/if}
</div>

<div class="description">
	{if $clientSecretValid}
		<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.clientSecretValid"}</p>
	{else}
		<p>{translate key="plugins.generic.orcidProfile.manager.status.configuration.clientSecretInvalid"}</p>
	{/if}
</div>



</div>


