{**
 * plugins/generic/orcidProfile/templates/orcidVerify.tpl
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2000-2017 John Willinsky
 * Copyright (c) 2018 University Library Heidelberg
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Page template to display from the OrcidHandler to show ORCID verification success or failure.
 *}
{include file="frontend/components/header.tpl"}

<div class="page page_message">
	{include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.orcidProfile.verify.title"}
	<div class="description">
	{if $verifySuccess}
		<span class="orcid">
			{$orcidIcon}
			<a href="{$orcid|escape}" target="_blank">
				{$orcid|escape}
			</a>
		</span>
		<div class="orcid-success">
		{translate key="plugins.generic.orcidProfile.verify.success"}
		</div>
		{if $sendSubmission}
			{if $sendSubmissionSuccess}
				<div class="orcid-success">
				{translate key="plugins.generic.orcidProfile.verify.sendSubmissionToOrcid.success"}
				</div>
			{else}
				<div class="orcid-failure">
				{translate key="plugins.generic.orcidProfile.verify.sendSubmissionToOrcid.failure"}
				</div>
			{/if}
		{elseif $submissionNotPublished}
			{translate key="plugins.generic.orcidProfile.verify.sendSubmissionToOrcid.notpublished"}
		{/if}
	{else}
		<div class="orcid-failure">
		{if $denied}
			{translate key="plugins.generic.orcidProfile.authDenied"}
		{elseif $authFailure}
			{translate key="plugins.generic.orcidProfile.authFailure"}
		{else}
			{translate key="plugins.generic.orcidProfile.verify.failure"}
		{/if}
		</div>
		{translate key="plugins.generic.orcidProfile.failure.contact"}
	{/if}
	</div>
</div>

{include file="frontend/components/footer.tpl"}
