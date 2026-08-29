{**
 * templates/admin/sectionList.tpl
 *
 * Indexing Page Manager — admin section list with drag-drop ordering, active
 * toggles, and rename/delete actions. Built-in sections can be renamed or
 * hidden but not deleted.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<div class="ipm-admin" data-csrf="{$csrfToken|escape}" data-plugin="{$pluginName|escape}">

    <div class="ipm-admin-toolbar">
        <div>
            <h2 class="ipm-admin-title">{translate key="plugins.generic.indexingPageManager.admin.sections.title"}</h2>
            <div class="ipm-admin-subtitle">{translate key="plugins.generic.indexingPageManager.admin.sections.subtitle"}</div>
        </div>
        <div class="ipm-admin-actions">
            <button type="button" class="ipm-btn" data-ipm-action="open" data-ipm-verb="indexes">
                ← {translate key="plugins.generic.indexingPageManager.admin.sections.backToIndexes"}
            </button>
            <button type="button" class="ipm-btn ipm-btn-primary" data-ipm-action="open" data-ipm-verb="sectionForm">
                + {translate key="plugins.generic.indexingPageManager.admin.sections.addSection"}
            </button>
        </div>
    </div>

    {if $rows|@count == 0}
        <div class="ipm-admin-empty">
            <div class="ipm-admin-empty-title">
                {translate key="plugins.generic.indexingPageManager.admin.sections.empty.title"}
            </div>
        </div>
    {else}
        <div class="ipm-admin-sections" data-ipm-sortable="sections">
        {foreach from=$rows item=row}
            {assign var=section value=$row.section}
            <div class="ipm-admin-section" data-section-id="{$section->getId()|escape}">
                <div class="ipm-admin-section-header">
                    <span class="ipm-admin-drag ipm-section-drag-handle">⋮⋮</span>
                    <div class="ipm-admin-section-name {if !$section->getIsActive()}is-inactive{/if}">
                        {$section->getDisplayName()|escape}
                    </div>
                    <span class="ipm-admin-badge ipm-admin-badge-count">
                        {$row.activeIndexes|escape}/{$row.totalIndexes|escape}
                    </span>
                    {if $section->getIsBuiltIn()}
                        <span class="ipm-admin-badge ipm-admin-badge-builtin">
                            {translate key="plugins.generic.indexingPageManager.admin.sections.builtIn"}
                        </span>
                    {else}
                        <span class="ipm-admin-badge ipm-admin-badge-custom">
                            {translate key="plugins.generic.indexingPageManager.admin.sections.custom"}
                        </span>
                    {/if}
                    <span class="ipm-admin-slug-hint" tabindex="0"
                          title="{translate key='plugins.generic.indexingPageManager.admin.sections.slugLabel' slug=$section->getSlug()|escape}"
                          aria-label="{translate key='plugins.generic.indexingPageManager.admin.sections.slugLabel' slug=$section->getSlug()|escape}">i</span>

                    <div class="ipm-admin-rowactions">
                        <button type="button"
                                class="ipm-toggle {if $section->getIsActive()}is-active{/if}"
                                data-ipm-action="toggleSection"
                                data-section-id="{$section->getId()|escape}"
                                aria-pressed="{if $section->getIsActive()}true{else}false{/if}"
                                aria-label="{translate key='plugins.generic.indexingPageManager.admin.sections.toggleActive'}"
                                title="{translate key='plugins.generic.indexingPageManager.admin.sections.toggleActive'}">
                            <span class="ipm-toggle-knob"></span>
                        </button>
                        <button type="button" class="ipm-btn ipm-btn-small"
                                data-ipm-action="open" data-ipm-verb="sectionForm"
                                data-ipm-extra-section-id="{$section->getId()|escape}">
                            {translate key="common.edit"}
                        </button>
                        {if !$section->getIsBuiltIn()}
                            <button type="button" class="ipm-btn ipm-btn-small ipm-btn-danger"
                                    data-ipm-action="deleteSection"
                                    data-section-id="{$section->getId()|escape}"
                                    data-section-name="{$section->getDisplayName()|escape}">
                                {translate key="common.delete"}
                            </button>
                        {else}
                            <button type="button" class="ipm-btn ipm-btn-small" disabled
                                    title="{translate key='plugins.generic.indexingPageManager.admin.sections.error.cannotDeleteBuiltIn'}">
                                {translate key="common.delete"}
                            </button>
                        {/if}
                    </div>
                </div>
            </div>
        {/foreach}
        </div>
    {/if}
</div>

<script type="text/javascript">
    (function () {
        function boot() { if (typeof window.ipmAdminInit === 'function') window.ipmAdminInit(); }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
</script>
