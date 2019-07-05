{**
 * plugins/generic/orcidProfile/templates/orcidVerify.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2000-2019 John Willinsky
 * Copyright (c) 2018-2019 University Library Heidelberg
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Page template to display from the OrcidHandler to show ORCID verification success or failure.
 *}
{include file="frontend/components/header.tpl"}

<div class="page page_message">
	{include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.orcidProfile.about.title"}
	<h2>
		{translate key="plugins.generic.orcidProfile.about.title"}
	</h2>
	<div class="description">
		{translate key="plugins.generic.orcidProfile.about.orcidExplanation"}
	</div>
	<h3>{translate key="plugins.generic.orcidProfile.about.howAndWhy.title"}</h3>
	<div class="description">
		{$contextName}
		{if $memberApi}
			{translate key="plugins.generic.orcidProfile.about.purpose.memberapi"}
		{else}
			{translate key="plugins.generic.orcidProfile.about.purpose.publicapi"}
		{/if}
		{translate key="plugins.generic.orcidProfile.about.authorize"}
	</div>
	<h3>{translate key="plugins.generic.orcidProfile.about.display.title"}</h3>
	<div class="description">
		{translate key="plugins.generic.orcidProfile.about.display"}
	</div>
	<h3>{translate key="plugins.generic.orcidProfile.about.display.title"}</h3>
	<div class="description">
		{translate key="plugins.generic.orcidProfile.about.display"}
	</div>
	{if $memberApi}
	<h3>{translate key="plugins.generic.orcidProfile.about.connect.title"}</h3>
	<div class="description">
		{translate key="plugins.generic.orcidProfile.about.connect"}
	</div>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
