{**
 * templates/admin/templateSelector.tpl — display template + column chooser.
 *
 * Four radio cards (logo only / logo+name / logo+name+description /
 * logo+description) with miniature SVG previews, plus a column-count chooser
 * (3 / 4 / 5). Routed through ipmSubmitWithFiles.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<script type="text/javascript">
    (function () {
        function boot() {
            if (window.ipmSubmitWithFiles) {
                window.ipmSubmitWithFiles('#indexingPageManagerTemplateForm', {
                    saveVerb: 'templateSave',
                    successVerb: 'templates',
                    successMessage: '{translate key="common.changesSaved"|escape:"javascript"}',
                    savingText: '{translate key="common.saving"|escape:"javascript"}',
                    formHost: document.getElementById('ipmModalBody') ? '#ipmModalBody' : '#ipmBody'
                });
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
</script>

<form
    class="pkp_form ipm-form"
    id="indexingPageManagerTemplateForm"
    method="post"
    action="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="templateSave" escape=false}"
>
    {csrf}

    <div class="ipm-form-header">
        <h2>{translate key="plugins.generic.indexingPageManager.admin.templateSelector.title"}</h2>
    </div>
    <p class="ipm-form-help">{translate key="plugins.generic.indexingPageManager.admin.templateSelector.help"}</p>

    <div class="ipm-template-grid">
        {foreach from=$templates item=tpl}
            <label class="ipm-template-card {if $displayTemplate == $tpl}is-selected{/if}">
                <input type="radio" name="displayTemplate" value="{$tpl|escape}"
                       {if $displayTemplate == $tpl}checked{/if}>

                <div class="ipm-template-card-thumb" aria-hidden="true">
                    {if $tpl == 'logos'}
                        {* Logo only — three large logo tiles, no captions. *}
                        <svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg">
                            <rect width="320" height="180" fill="#ffffff"/>
                            <rect x="14" y="14" width="90" height="6" rx="1" fill="#4b5563"/>
                            <g fill="#f8f9fb" stroke="#e5e7eb">
                                <rect x="22" y="40" width="80" height="108" rx="6"/>
                                <rect x="120" y="40" width="80" height="108" rx="6"/>
                                <rect x="218" y="40" width="80" height="108" rx="6"/>
                            </g>
                            <g fill="#cbd5e1">
                                <rect x="40" y="86" width="44" height="16" rx="3"/>
                                <rect x="138" y="86" width="44" height="16" rx="3"/>
                                <rect x="236" y="86" width="44" height="16" rx="3"/>
                            </g>
                        </svg>
                    {elseif $tpl == 'logo-name'}
                        {* Logo + name — tile with a name line under the logo. *}
                        <svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg">
                            <rect width="320" height="180" fill="#ffffff"/>
                            <rect x="14" y="14" width="90" height="6" rx="1" fill="#4b5563"/>
                            <g fill="#f8f9fb" stroke="#e5e7eb">
                                <rect x="22" y="40" width="80" height="108" rx="6"/>
                                <rect x="120" y="40" width="80" height="108" rx="6"/>
                                <rect x="218" y="40" width="80" height="108" rx="6"/>
                            </g>
                            <g fill="#cbd5e1">
                                <rect x="40" y="64" width="44" height="16" rx="3"/>
                                <rect x="138" y="64" width="44" height="16" rx="3"/>
                                <rect x="236" y="64" width="44" height="16" rx="3"/>
                            </g>
                            <g fill="#4b5563">
                                <rect x="36" y="112" width="52" height="7" rx="2"/>
                                <rect x="134" y="112" width="52" height="7" rx="2"/>
                                <rect x="232" y="112" width="52" height="7" rx="2"/>
                            </g>
                        </svg>
                    {elseif $tpl == 'logo-name-desc'}
                        {* Logo + name + description. *}
                        <svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg">
                            <rect width="320" height="180" fill="#ffffff"/>
                            <rect x="14" y="14" width="90" height="6" rx="1" fill="#4b5563"/>
                            <g fill="#f8f9fb" stroke="#e5e7eb">
                                <rect x="22" y="34" width="80" height="120" rx="6"/>
                                <rect x="120" y="34" width="80" height="120" rx="6"/>
                                <rect x="218" y="34" width="80" height="120" rx="6"/>
                            </g>
                            <g fill="#cbd5e1">
                                <rect x="40" y="54" width="44" height="16" rx="3"/>
                                <rect x="138" y="54" width="44" height="16" rx="3"/>
                                <rect x="236" y="54" width="44" height="16" rx="3"/>
                            </g>
                            <g fill="#4b5563">
                                <rect x="36" y="100" width="52" height="6" rx="2"/>
                                <rect x="134" y="100" width="52" height="6" rx="2"/>
                                <rect x="232" y="100" width="52" height="6" rx="2"/>
                            </g>
                            <g fill="#cbd5e1">
                                <rect x="32" y="114" width="60" height="4" rx="1"/>
                                <rect x="40" y="122" width="44" height="4" rx="1"/>
                                <rect x="130" y="114" width="60" height="4" rx="1"/>
                                <rect x="138" y="122" width="44" height="4" rx="1"/>
                                <rect x="228" y="114" width="60" height="4" rx="1"/>
                                <rect x="236" y="122" width="44" height="4" rx="1"/>
                            </g>
                        </svg>
                    {elseif $tpl == 'logo-desc'}
                        {* Logo + description (no name). *}
                        <svg viewBox="0 0 320 180" xmlns="http://www.w3.org/2000/svg">
                            <rect width="320" height="180" fill="#ffffff"/>
                            <rect x="14" y="14" width="90" height="6" rx="1" fill="#4b5563"/>
                            <g fill="#f8f9fb" stroke="#e5e7eb">
                                <rect x="22" y="40" width="80" height="108" rx="6"/>
                                <rect x="120" y="40" width="80" height="108" rx="6"/>
                                <rect x="218" y="40" width="80" height="108" rx="6"/>
                            </g>
                            <g fill="#cbd5e1">
                                <rect x="40" y="62" width="44" height="16" rx="3"/>
                                <rect x="138" y="62" width="44" height="16" rx="3"/>
                                <rect x="236" y="62" width="44" height="16" rx="3"/>
                            </g>
                            <g fill="#9ca3af">
                                <rect x="32" y="110" width="60" height="4" rx="1"/>
                                <rect x="40" y="118" width="44" height="4" rx="1"/>
                                <rect x="130" y="110" width="60" height="4" rx="1"/>
                                <rect x="138" y="118" width="44" height="4" rx="1"/>
                                <rect x="228" y="110" width="60" height="4" rx="1"/>
                                <rect x="236" y="118" width="44" height="4" rx="1"/>
                            </g>
                        </svg>
                    {/if}
                </div>

                <div class="ipm-template-card-name">
                    {translate key="plugins.generic.indexingPageManager.settings.displayTemplate.`$tpl`"}
                </div>
                <div class="ipm-template-card-desc">
                    {translate key="plugins.generic.indexingPageManager.settings.displayTemplate.`$tpl`.desc"}
                </div>
            </label>
        {/foreach}
    </div>

    <section class="ipm-form-card">
        <div class="ipm-form-card-title">
            {translate key="plugins.generic.indexingPageManager.settings.displayColumns"}
        </div>
        <div class="ipm-radio-row">
            {foreach from=$columnOptions item=col}
                <label class="ipm-radio">
                    <input type="radio" name="displayColumns" value="{$col|escape}"
                           {if $displayColumns == $col}checked{/if}>
                    <span>{$col|escape} {translate key="plugins.generic.indexingPageManager.admin.indexList.stats.cols"}</span>
                </label>
            {/foreach}
        </div>
        <div class="ipm-form-help">
            {translate key="plugins.generic.indexingPageManager.settings.displayColumnsHelp"}
        </div>
    </section>

    <div class="ipm-form-buttons">
        <button type="submit" class="ipm-btn ipm-btn-primary">
            {translate key="common.save"}
        </button>
    </div>
</form>
