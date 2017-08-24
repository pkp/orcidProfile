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
<script>
    function openSearchORCID() {ldelim}
        $.ajax({ldelim}
            url: "{url router="page" page="orcidapi" op="orcidSearch" escape=false}",
            data: {ldelim}'orcid': $("input[name=orcid]").val(){rdelim},
            dataType: 'json',
            success: function(jsonData) {ldelim}
                if (jsonData.status == true) {ldelim}
                        alert(jsonData.content);
                    {rdelim} else {ldelim}
                        alert(jsonData.content);
                    {rdelim}
            {rdelim}
        {rdelim});
    {rdelim}
</script>

<div id='orcidLoginLink'>
	<a href="#" onclick="return openSearchORCID();">
		<img id="orcid-id-logo" src="http://orcid.org/sites/default/files/images/orcid_16x16.png" width='16' height='16' alt="ORCID logo"/>
		{translate key='plugins.generic.orcidProfile.searchOrcidExists'}
	</a>
</div>
