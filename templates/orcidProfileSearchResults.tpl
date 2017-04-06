{**
 * plugins/generic/orcidProfile/orcidProfileSearchResults.tpl
 *
 * Copyright (c) 2015-2017 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Contributed by 4Science (http://www.4science.it).
 *
 * ORCID Profile search results
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
    <link rel="stylesheet" href="{$baseUrl}/plugins/generic/orcidProfile/css/orcidProfile.css" type="text/css" />

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
            $("#orcidSearchResultsSubmit").click(function(event) {
                event.preventDefault();
                var profiles = {/literal}{$orcidSearchResults|@json_encode}{literal};                
                var authorIndex = {/literal}{$authorIndex}{literal};                
                var profileSelectedIndex = $("input[name=orcidProfile]:checked").val();
                var profileSelected = profiles.theArray[profileSelectedIndex];
                
                opener.document.getElementById("{/literal}{$orcidButtonId}{literal}").style.display = "none";;
                opener.document.getElementById("remove-orcid-button-" + authorIndex).style.display = "inline";;
                opener.document.getElementById("{/literal}{$orcidInputId}{literal}").value = profileSelected.orcidiD;
                if (opener.document.getElementById("authors-" + authorIndex + "-firstName").value == '') {
                    opener.document.getElementById("authors-" + authorIndex + "-firstName").value = profileSelected.name;     
                }
                if (opener.document.getElementById("authors-" + authorIndex + "-lastName").value == '') {
                    opener.document.getElementById("authors-" + authorIndex + "-lastName").value = profileSelected.lastname;     
                }
                if (opener.document.getElementById("authors-" + authorIndex + "-email").value == '') {
                    opener.document.getElementById("authors-" + authorIndex + "-email").value = profileSelected.email;     
                }
                window.close();
                
            });
            
            $("#orcidSearchResultsBack").click(function(event) {
                document.location.href = "{/literal}{url|escape:"javascript" page="orcidapi" op="orcidSearch" targetOp="form" orcidInputId=$orcidInputId orcidButtonId=$orcidButtonId authorIndex=$authorIndex escape=false}{literal}";
            });              
        });
    
    </script>
{/literal}    

<h3>{translate key='plugins.generic.orcidProfile.searchPageTitle'}</h3>
<div id="content" class="search-content">
    <p>{translate key='plugins.generic.orcidProfile.searchResultsList'}</p>    
    <form action="{plugin_url path="process"}" method="post" id="issuesForm">
        <input type="hidden" name="target" value="issue" />
        <table width="100%" class="listing">
            <tr>
                <td colspan="5" class="headseparator">&nbsp;</td>
            </tr>
            <tr class="heading" valign="top">
                <td width="5%">&nbsp;</td>
                <td width="25%">{translate key="plugins.generic.openAIRE.projectID"}</td>
                <td width="25%">{translate key="user.email"}</td>
                <td width="25%">{translate key="plugins.generic.orcidProfile.affiliations"}</td>
                <td width="20%">{translate key="plugins.generic.orcidProfile.researcherUrl"}</td>
            </tr>
            <tr>
                <td colspan="5" class="headseparator">&nbsp;</td>
            </tr>
            {capture "default"}
                {translate key='plugins.generic.orcidProfile.privateEmail}
            {/capture}

            {iterate from=orcidSearchResults key="index" item=profile}
                <tr valign="middle">
                    <td><input type="radio" name="orcidProfile" value='{$index}'/></td>
                    <td>{$profile.name|escape} {$profile.lastname|escape}</td>
                    <td>{$profile.email|default:$smarty.capture.default}</td>
                    <td>
                        {foreach from=$profile.affiliations item=affiliation}
                            <p>{$affiliation}</p>
                        {/foreach}                  
                    </td>
                    <td>
                        {foreach from=$profile.researcherUrls item=researcherUrl}
                            <p>{$researcherUrl}</p>
                        {/foreach}                   
                    </td>                    
                </tr>
                <tr>
                    <td colspan="5" class="separator">&nbsp;</td>
                </tr>
            {/iterate}
            {if $orcidSearchResults->wasEmpty()}
                <tr valign="top">
                    <td colspan="5">{translate key='plugins.generic.orcidProfile.noData'}</td>
                </tr>            
                <tr>
                    <td colspan="5" class="endseparator">&nbsp;</td>
                </tr>
            {else}
                <tr>
                    <td colspan="2" align="left">{page_info iterator=$orcidSearchResults}</td>
                    <td colspan="3" align="right">{page_links anchor="profiles" name="profiles" targetOp="search" searchOrcidName=$searchOrcidName searchOrcidLastname=$searchOrcidLastname searchOrcidEmail=$searchOrcidEmail iterator=$orcidSearchResults}</td>
                </tr>
            {/if}
        </table>
        <p><b>{translate key='plugins.generic.orcidProfile.searchResultsNotice'}</b></p>
        <p>
            <input id="orcidSearchResultsSubmit" type="submit" value="{translate key='plugins.generic.orcidProfile.submitAction'}" class="button defaultButton">            
            <input id="orcidSearchResultsBack" type="button" value="{translate key='common.back'}" class="button">           
            <input type="button" value="{translate key='common.close'}" class="button" onclick="window.close();">
        </p>
    </form>

</div>
