{**
 * templates/admin/settings.tpl — settings panel.
 *
 * Sections: Page (title, intro, slug), Display (template, columns, SEO),
 * Status (read-only summary). Routed through window.ipmSubmitWithFiles for the
 * uniform response contract. No inline <style> — Vue strips it; see admin.css.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<script type="text/javascript">
    (function () {
        function boot() {
            if (window.ipmSubmitWithFiles) {
                window.ipmSubmitWithFiles('#indexingPageManagerSettingsForm', {
                    saveVerb: 'settingsSave',
                    successVerb: 'settings',
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
    id="indexingPageManagerSettingsForm"
    method="post"
    action="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="settingsSave" escape=false}"
>
    {csrf}

    <div class="ipm-form-header">
        <h2>{translate key="plugins.generic.indexingPageManager.settings.title"}</h2>
    </div>

    {* Page section *}
    <section class="ipm-form-card">
        <div class="ipm-form-card-header">
            <div>
                <div class="ipm-form-card-title">
                    {translate key="plugins.generic.indexingPageManager.settings.section.page"}
                </div>
            </div>
        </div>

        <div class="ipm-multi-locale-field">
            <div class="ipm-multi-locale-label">
                {translate key="plugins.generic.indexingPageManager.settings.pageTitle"}
            </div>
            <div class="ipm-locale-grid">
                {foreach from=$supportedLocales item=loc}
                    <div class="ipm-locale-cell" data-locale="{$loc|escape}">
                        <span class="ipm-locale-cell-badge">
                            <span class="ipm-locale-badge">{$localeNames[$loc]|default:$loc|escape}</span>
                        </span>
                        <input type="text" name="pageTitle[{$loc|escape}]"
                               value="{$pageTitle.$loc|default:''|escape}">
                    </div>
                {/foreach}
            </div>
        </div>

        <div class="ipm-multi-locale-field">
            <div class="ipm-multi-locale-label">
                {translate key="plugins.generic.indexingPageManager.settings.introText"}
            </div>
            <div class="ipm-locale-grid">
                {foreach from=$supportedLocales item=loc}
                    <div class="ipm-locale-cell" data-locale="{$loc|escape}">
                        <span class="ipm-locale-cell-badge">
                            <span class="ipm-locale-badge">{$localeNames[$loc]|default:$loc|escape}</span>
                        </span>
                        <textarea name="introText[{$loc|escape}]" rows="2">{$introText.$loc|default:''|escape}</textarea>
                    </div>
                {/foreach}
            </div>
        </div>

        <div class="ipm-form-field">
            <label>{translate key="plugins.generic.indexingPageManager.settings.pageSlug"}</label>
            <input type="text" name="pageSlug" value="{$pageSlug|default:'databases'|escape}"
                   placeholder="databases" spellcheck="false" autocomplete="off">
            <div class="ipm-form-help">
                {translate key="plugins.generic.indexingPageManager.settings.pageSlugHelp"}
            </div>
        </div>
    </section>

    {* Display section *}
    <section class="ipm-form-card">
        <div class="ipm-form-card-title">
            {translate key="plugins.generic.indexingPageManager.settings.section.display"}
        </div>

        <div class="ipm-form-field">
            <label>{translate key="plugins.generic.indexingPageManager.settings.displayTemplate"}</label>
            <select name="displayTemplate">
                {foreach from=$templates item=tpl}
                    <option value="{$tpl|escape}" {if $displayTemplate == $tpl}selected{/if}>
                        {translate key="plugins.generic.indexingPageManager.settings.displayTemplate.`$tpl`"}
                    </option>
                {/foreach}
            </select>
        </div>

        <div class="ipm-form-field">
            <label class="ipm-form-label">{translate key="plugins.generic.indexingPageManager.settings.displayColumns"}</label>
            <div class="ipm-radio-row">
                {foreach from=$columnOptions item=col}
                    <label class="ipm-radio">
                        <input type="radio" name="displayColumns" value="{$col|escape}"
                               {if $displayColumns == $col}checked{/if}>
                        <span>{$col|escape}</span>
                    </label>
                {/foreach}
            </div>
            <div class="ipm-form-help">
                {translate key="plugins.generic.indexingPageManager.settings.displayColumnsHelp"}
            </div>
        </div>
    </section>

    {* SEO section *}
    <section class="ipm-form-card">
        <div class="ipm-form-card-title">
            {translate key="plugins.generic.indexingPageManager.settings.section.seo"}
        </div>
        <label class="ipm-checkbox-row">
            <input type="checkbox" name="enableSchemaOrg" value="1" {if $enableSchemaOrg}checked{/if}>
            <span>{translate key="plugins.generic.indexingPageManager.settings.enableSchemaOrg"}</span>
        </label>
        <div class="ipm-form-help">
            {translate key="plugins.generic.indexingPageManager.settings.enableSchemaOrgHelp"}
        </div>
    </section>

    {* Status section (read-only) *}
    <section class="ipm-form-card">
        <div class="ipm-form-card-title">
            {translate key="plugins.generic.indexingPageManager.settings.status.heading"}
        </div>
        <div class="ipm-status-grid">
            <div class="ipm-status-item">
                <div class="ipm-status-label">{translate key="plugins.generic.indexingPageManager.settings.status.activation"}</div>
                <div class="ipm-status-value ipm-status-on">● {translate key="plugins.generic.indexingPageManager.settings.status.enabled"}</div>
                <div class="ipm-status-note">{translate key="plugins.generic.indexingPageManager.settings.status.toggleHint"}</div>
            </div>
            <div class="ipm-status-item">
                <div class="ipm-status-label">{translate key="plugins.generic.indexingPageManager.settings.status.publicUrl"}</div>
                <div class="ipm-status-value">
                    <a href="{$publicUrl|escape}" target="_blank" rel="noopener" class="ipm-status-link">{$publicUrl|escape}</a>
                </div>
                <div class="ipm-status-note">{translate key="plugins.generic.indexingPageManager.settings.status.routeNote"}</div>
            </div>
            <div class="ipm-status-item">
                <div class="ipm-status-label">{translate key="plugins.generic.indexingPageManager.admin.indexList.stats.sections"}</div>
                <div class="ipm-status-value">{$sectionCount|escape}</div>
            </div>
        </div>
    </section>

    <div class="ipm-form-buttons">
        <button type="submit" class="ipm-btn ipm-btn-primary">
            {translate key="common.save"}
        </button>
    </div>
</form>
