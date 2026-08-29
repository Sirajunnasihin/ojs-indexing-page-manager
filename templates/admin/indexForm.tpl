{**
 * templates/admin/indexForm.tpl
 *
 * Indexing Page Manager — index add/edit form.
 *
 * Carries a real <input type="file"> (logo upload), so it submits via
 * window.ipmSubmitWithFiles (FormData + fetch) — NOT OJS' AjaxFormHandler,
 * which drops file inputs from the multipart body. On validation failure the
 * server returns the re-rendered form in `formHtml`, which the helper swaps
 * back into #ipmBody.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<script type="text/javascript">
    (function () {
        // Defer to DOMContentLoaded: the helper is defined later in _page.tpl
        // and the <form> below is parsed after this script, so binding must
        // wait until both exist. On the validation-failure re-inject path the
        // doc is already complete, so boot() runs immediately then.
        function boot() {
            if (window.ipmSubmitWithFiles) {
                window.ipmSubmitWithFiles('#indexingPageManagerIndexForm', {
                    // Used by the modal admin (IndexingPageManagerPlugin::manage());
                    // ignored by the legacy custom-page admin, which POSTs to
                    // the form's own `action` URL below instead.
                    saveVerb: 'indexSave',
                    successVerb: 'manage',
                    successUrl: '{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="indexes" escape=false}',
                    successMessage: '{translate key="plugins.generic.indexingPageManager.admin.indexForm.saved"|escape:"javascript"}',
                    savingText: '{translate key="common.saving"|escape:"javascript"}',
                    formHost: document.getElementById('ipmModalBody') ? '#ipmModalBody' : '#ipmBody'
                });
            }
            if (typeof window.ipmIndexFormInit === 'function') window.ipmIndexFormInit();
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
    id="indexingPageManagerIndexForm"
    method="post"
    enctype="multipart/form-data"
    action="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="indexSave" escape=false}"
>
    {csrf}

    {if $indexId}<input type="hidden" name="indexId" value="{$indexId|escape}">{/if}
    <input type="hidden" name="temporaryFileId" id="ipmTemporaryFileId" value="">
    <input type="hidden" name="removeLogo" id="ipmRemoveLogo" value="0">

    <div class="ipm-form-header">
        <h2>
            {if $indexId}
                {translate key="plugins.generic.indexingPageManager.admin.indexForm.editTitle"}
            {else}
                {translate key="plugins.generic.indexingPageManager.admin.indexForm.addTitle"}
            {/if}
        </h2>
    </div>

    <div class="ipm-form-grid">

        {* LEFT: logo + status + sections *}
        <aside class="ipm-form-side">

            <section class="ipm-form-card">
                <h3 class="ipm-form-section-label">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.logo"}
                </h3>
                <div class="ipm-logo-preview">
                    {if $logoUrl}
                        <img id="ipmLogoImg" src="{$logoUrl|escape}" alt="">
                    {else}
                        <div id="ipmLogoImg" class="ipm-logo-placeholder">
                            {translate key="plugins.generic.indexingPageManager.admin.indexForm.logoPlaceholder"}
                        </div>
                    {/if}
                </div>

                <label class="ipm-btn ipm-btn-block">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.logoChoose"}
                    {* name="logoFile" — server reads $_FILES['logoFile'] directly. *}
                    <input type="file" id="ipmLogoInput" name="logoFile"
                           accept="image/jpeg,image/png,image/webp"
                           style="display:none">
                </label>
                {if $logoUrl}
                <button type="button" class="ipm-btn ipm-btn-block ipm-btn-danger" id="ipmLogoRemove">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.logoRemove"}
                </button>
                {/if}
                <div class="ipm-form-help">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.logoHelp"}
                </div>
            </section>

            <section class="ipm-form-card">
                <h3 class="ipm-form-section-label">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.status"}
                </h3>
                <label class="ipm-checkbox-row">
                    <input type="checkbox" name="isActive" value="1" {if $isActive}checked{/if}>
                    <span>{translate key="plugins.generic.indexingPageManager.admin.indexForm.isActive"}</span>
                </label>
                <div class="ipm-form-help">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.isActiveHelp"}
                </div>
            </section>

            <section class="ipm-form-card">
                <h3 class="ipm-form-section-label">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.sections"} *
                </h3>
                {assign var=selectedSectionIds value=$sectionIds|default:[]}
                {foreach from=$sectionOptions item=opt}
                    <label class="ipm-checkbox-row {if in_array($opt.id, $selectedSectionIds)}is-selected{/if}">
                        <input type="checkbox" name="sectionIds[]" value="{$opt.id|escape}"
                               {if in_array($opt.id, $selectedSectionIds)}checked{/if}>
                        <span>{$opt.name|escape}</span>
                        {if $opt.isBuiltIn}
                            <span class="ipm-form-tag">{translate key="plugins.generic.indexingPageManager.admin.sections.builtIn"}</span>
                        {/if}
                    </label>
                {/foreach}
            </section>

        </aside>

        {* RIGHT: localised name + description + url *}
        <div class="ipm-form-main">

            <section class="ipm-form-card">
                <div class="ipm-form-card-header">
                    <div>
                        <div class="ipm-form-card-title">
                            {translate key="plugins.generic.indexingPageManager.admin.indexForm.details"}
                        </div>
                        <div class="ipm-form-card-sub">
                            {translate key="plugins.generic.indexingPageManager.admin.indexForm.detailsHelp"}
                        </div>
                    </div>
                </div>

                {* Name — TR | EN side-by-side *}
                <div class="ipm-multi-locale-field">
                    <div class="ipm-multi-locale-label">
                        {translate key="plugins.generic.indexingPageManager.admin.indexForm.name"}
                    </div>
                    <div class="ipm-locale-grid">
                        {foreach from=$supportedLocales item=loc}
                            <div class="ipm-locale-cell" data-locale="{$loc|escape}">
                                <span class="ipm-locale-cell-badge">
                                    <span class="ipm-locale-badge">{$localeNames[$loc]|default:$loc|escape}</span>
                                    {if $loc == $primaryLocale}<span class="ipm-required" title="required">*</span>{/if}
                                </span>
                                <input type="text" name="name[{$loc|escape}]"
                                       value="{$name.$loc|default:''|escape}">
                            </div>
                        {/foreach}
                    </div>
                </div>

                {* Description — textareas side-by-side per locale *}
                <div class="ipm-multi-locale-field">
                    <div class="ipm-multi-locale-label">
                        {translate key="plugins.generic.indexingPageManager.admin.indexForm.description"}
                    </div>
                    <div class="ipm-locale-grid">
                        {foreach from=$supportedLocales item=loc}
                            <div class="ipm-locale-cell" data-locale="{$loc|escape}">
                                <span class="ipm-locale-cell-badge">
                                    <span class="ipm-locale-badge">{$localeNames[$loc]|default:$loc|escape}</span>
                                </span>
                                <textarea name="description[{$loc|escape}]" rows="3">{$description.$loc|default:''|escape}</textarea>
                            </div>
                        {/foreach}
                    </div>
                    <div class="ipm-form-help">
                        {translate key="plugins.generic.indexingPageManager.admin.indexForm.descriptionHelp"}
                    </div>
                </div>
            </section>

            <section class="ipm-form-card">
                <div class="ipm-form-card-title">
                    {translate key="plugins.generic.indexingPageManager.admin.indexForm.link"}
                </div>
                <div class="ipm-form-field">
                    <label>{translate key="plugins.generic.indexingPageManager.admin.indexForm.url"}</label>
                    <input type="url" name="url" value="{$url|default:''|escape}" placeholder="https://...">
                    <div class="ipm-form-help">
                        {translate key="plugins.generic.indexingPageManager.admin.indexForm.urlHelp"}
                    </div>
                </div>
            </section>

        </div>

    </div>

    <div class="ipm-form-buttons">
        <button type="submit" class="ipm-btn ipm-btn-primary">
            {translate key="common.save"}
        </button>
    </div>
</form>
