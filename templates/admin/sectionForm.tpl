{**
 * templates/admin/sectionForm.tpl — section add/edit form.
 *
 * No file input, but routed through window.ipmSubmitWithFiles for a uniform
 * {ok, redirect, message, formHtml} response contract across all admin forms.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<script type="text/javascript">
    (function () {
        function boot() {
            if (window.ipmSubmitWithFiles) {
                window.ipmSubmitWithFiles('#indexingPageManagerSectionForm', {
                    saveVerb: 'sectionSave',
                    successVerb: 'sections',
                    successUrl: '{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="sections" escape=false}',
                    successMessage: '{translate key="plugins.generic.indexingPageManager.admin.sectionForm.saved"|escape:"javascript"}',
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
    id="indexingPageManagerSectionForm"
    method="post"
    action="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="sectionSave" escape=false}"
>
    {csrf}

    {if $sectionId}<input type="hidden" name="sectionId" value="{$sectionId|escape}">{/if}

    <div class="ipm-form-header">
        <h2>
            {if $sectionId}
                {translate key="plugins.generic.indexingPageManager.admin.sectionForm.editTitle"}
            {else}
                {translate key="plugins.generic.indexingPageManager.admin.sectionForm.addTitle"}
            {/if}
        </h2>
    </div>

    <section class="ipm-form-card">
        <div class="ipm-form-card-header">
            <div>
                <div class="ipm-form-card-title">
                    {translate key="plugins.generic.indexingPageManager.admin.sectionForm.displayName"}
                </div>
                <div class="ipm-form-card-sub">
                    {translate key="plugins.generic.indexingPageManager.admin.sectionForm.displayNameHelp"}
                </div>
            </div>
        </div>

        <div class="ipm-locale-grid">
            {foreach from=$supportedLocales item=loc}
                <div class="ipm-locale-cell" data-locale="{$loc|escape}">
                    <label class="ipm-locale-label">
                        <span class="ipm-locale-badge">{$localeNames[$loc]|default:$loc|escape}</span>
                        {translate key="plugins.generic.indexingPageManager.admin.sectionForm.displayName"}
                        {if $loc == $primaryLocale} <span class="ipm-required">*</span>{/if}
                    </label>
                    <input type="text"
                           name="displayName[{$loc|escape}]"
                           value="{$displayName.$loc|default:''|escape}"
                           placeholder="{$loc|escape}">
                </div>
            {/foreach}
        </div>
    </section>

    {* Slug is auto-derived from the primary-locale display name on save and
       is immutable for built-in sections. We keep a hidden input on edit so
       the existing slug round-trips without being regenerated. *}
    {if $sectionId && $slug}
        <input type="hidden" name="slug" value="{$slug|escape}">
    {/if}

    <section class="ipm-form-card">
        <label class="ipm-checkbox-row">
            <input type="checkbox" name="isActive" value="1" {if $isActive}checked{/if}>
            <span>{translate key="plugins.generic.indexingPageManager.admin.sectionForm.isActive"}</span>
        </label>
        <div class="ipm-form-help">
            {translate key="plugins.generic.indexingPageManager.admin.sectionForm.isActiveHelp"}
        </div>
    </section>

    <div class="ipm-form-buttons">
        <button type="submit" class="ipm-btn ipm-btn-primary">
            {translate key="common.save"}
        </button>
    </div>
</form>
