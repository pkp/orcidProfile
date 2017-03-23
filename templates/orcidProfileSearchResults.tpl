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
                var profiles = {/literal}{$orcidSearchResults|@json_encode}{literal};                
                var authorIndex = {/literal}{$authorIndex}{literal};                
                var profileSelectedIndex = $("#orcidSearchResults").val();
                var profileSelected = profiles[profileSelectedIndex];
                
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
        });
    
    </script>
{/literal}    

<h3>{translate key='plugins.generic.orcidProfile.searchPageTitle'}</h3>
<div id="content" class="search-content">
    {if $orcidSearchResults|@count > 0}
        {capture "default"}
            {translate key='plugins.generic.orcidProfile.privateEmail}
        {/capture}
        <p>{translate key='plugins.generic.orcidProfile.searchResultsList'}</p>
        <select name="orcidSearchResults" id="orcidSearchResults" size="5" class="selectMenu" style="width: 100%; height:180px;">        
            {foreach from=$orcidSearchResults key="index" item=profile}            
                <option value="{$index}">{$profile.name} {$profile.lastname} - {translate key='user.email'} : {$profile.email|default:$smarty.capture.default}</option>
            {/foreach}
            

        </select>
        <p>
            <input id="orcidSearchResultsSubmit" type="submit" value="{translate key='plugins.generic.orcidProfile.submitAction'}" class="button defaultButton"> <input type="button" value="{translate key="common.cancel"}" class="button" onclick="window.history.back();">
        </p>
    {else}
        <h4>{translate key='plugins.generic.orcidProfile.noData'}</h4>
        <p>
            <input type="button" value="{translate key='common.back'}" class="button" onclick="window.history.back();">
        </p>
    {/if}
</div>
