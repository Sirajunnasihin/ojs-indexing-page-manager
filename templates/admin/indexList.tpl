{**
 * templates/admin/indexList.tpl
 *
 * Indexing Page Manager — admin index list, grouped by section with
 * drag-drop ordering, active toggles, and edit/delete row actions.
 *}
{* assets loaded by admin/_page.tpl wrapper *}

<div class="ipm-admin" data-csrf="{$csrfToken|escape}" data-plugin="{$pluginName|escape}">

    <div class="ipm-admin-toolbar">
        <div>
            <h2 class="ipm-admin-title">{translate key="plugins.generic.indexingPageManager.admin.indexList.title"}</h2>
            <div class="ipm-admin-subtitle">{translate key="plugins.generic.indexingPageManager.admin.indexList.subtitle"}</div>
        </div>
        <div class="ipm-admin-actions">
            <a class="ipm-btn" href="{$previewUrl|escape}" target="_blank" rel="noopener">
                {translate key="plugins.generic.indexingPageManager.admin.indexList.preview"}
            </a>
            <button type="button" class="ipm-btn ipm-btn-primary" data-ipm-action="open" data-ipm-verb="indexForm">
                + {translate key="plugins.generic.indexingPageManager.admin.indexList.addIndex"}
            </button>
        </div>
    </div>

    <div class="ipm-admin-stats">
        <div class="ipm-stat-card">
            <div class="ipm-stat-label">{translate key="plugins.generic.indexingPageManager.admin.indexList.stats.total"}</div>
            <div class="ipm-stat-value">{$totalIndexes|escape}</div>
        </div>
        <div class="ipm-stat-card">
            <div class="ipm-stat-label">{translate key="plugins.generic.indexingPageManager.admin.indexList.stats.active"}</div>
            <div class="ipm-stat-value ipm-stat-success">{$activeIndexes|escape}</div>
        </div>
        <div class="ipm-stat-card">
            <div class="ipm-stat-label">{translate key="plugins.generic.indexingPageManager.admin.indexList.stats.sections"}</div>
            <div class="ipm-stat-value">{$usedSectionCount|escape}<span class="ipm-stat-suffix"> / {$totalSections|escape}</span></div>
        </div>
        <div class="ipm-stat-card">
            <div class="ipm-stat-label">{translate key="plugins.generic.indexingPageManager.admin.indexList.stats.template"}</div>
            <div class="ipm-stat-value ipm-stat-value-small">
                {translate key="plugins.generic.indexingPageManager.settings.displayTemplate.`$activeTemplate`"}
                <span class="ipm-stat-suffix"> · {$activeColumns|escape} {translate key="plugins.generic.indexingPageManager.admin.indexList.stats.cols"}</span>
            </div>
        </div>
    </div>

    {if $indexBlocks|@count == 0}
        <div class="ipm-admin-empty">
            <div class="ipm-admin-empty-title">
                {translate key="plugins.generic.indexingPageManager.admin.indexList.empty.title"}
            </div>
            <div class="ipm-admin-empty-body">
                {translate key="plugins.generic.indexingPageManager.admin.indexList.empty.body"}
            </div>
            <button type="button" class="ipm-btn ipm-btn-primary" data-ipm-action="open" data-ipm-verb="indexForm">
                + {translate key="plugins.generic.indexingPageManager.admin.indexList.addIndex"}
            </button>
        </div>
    {else}
        <div class="ipm-admin-sections" data-ipm-sortable="sections">
        {foreach from=$indexBlocks item=block}
            {assign var=section value=$block.section}
            {assign var=indexes value=$block.indexes}
            <div class="ipm-admin-section" data-section-id="{$section->getId()|escape}">
                <div class="ipm-admin-section-header">
                    <span class="ipm-admin-drag ipm-section-drag-handle" title="{translate key='plugins.generic.indexingPageManager.admin.indexList.dragSection'}">⋮⋮</span>
                    <div class="ipm-admin-section-name {if !$section->getIsActive()}is-inactive{/if}">
                        {$section->getDisplayName()|escape}
                    </div>
                    <span class="ipm-admin-badge ipm-admin-badge-count">{$indexes|@count|escape}</span>
                    {if !$section->getIsActive()}
                        <span class="ipm-admin-badge ipm-admin-badge-warn">
                            {translate key="plugins.generic.indexingPageManager.admin.sections.inactive"}
                        </span>
                    {/if}
                    <button type="button" class="ipm-btn ipm-btn-small ipm-admin-add-to-sec"
                            data-ipm-action="open" data-ipm-verb="indexForm"
                            data-ipm-extra-section-id="{$section->getId()|escape}">
                        + {translate key="plugins.generic.indexingPageManager.admin.indexList.addToThis"}
                    </button>
                </div>

                {if $indexes|@count == 0}
                    <div class="ipm-admin-section-empty">
                        {translate key="plugins.generic.indexingPageManager.admin.indexList.sectionEmpty"}
                    </div>
                {else}
                    <div class="ipm-admin-rows" data-ipm-sortable="indexes" data-section-id="{$section->getId()|escape}">
                    {foreach from=$indexes item=index}
                        <div class="ipm-admin-row {if !$index->getIsActive()}is-inactive{/if}" data-index-id="{$index->getId()|escape}">
                            <span class="ipm-admin-drag ipm-index-drag-handle" title="{translate key='plugins.generic.indexingPageManager.admin.indexList.dragIndex'}">⋮⋮</span>
                            <div class="ipm-admin-thumb">
                                {if $index->getLogoPath()}
                                    {capture assign=ipmThumbSrc}{ipm_logo_url path=$index->getLogoPath() context_id=$contextId}{/capture}
                                    <img src="{$ipmThumbSrc|escape}" alt="">
                                {else}
                                    <span class="ipm-admin-thumb-empty" aria-hidden="true">—</span>
                                {/if}
                            </div>
                            <div class="ipm-admin-rowmeta">
                                <div class="ipm-admin-rowname">{$index->getName()|escape}</div>
                                {if $index->getUrl()}
                                    <div class="ipm-admin-rowsub">{$index->getUrl()|escape}</div>
                                {/if}
                            </div>
                            <div class="ipm-admin-rowactions">
                                <button type="button"
                                        class="ipm-toggle {if $index->getIsActive()}is-active{/if}"
                                        data-ipm-action="toggleIndex"
                                        data-index-id="{$index->getId()|escape}"
                                        aria-pressed="{if $index->getIsActive()}true{else}false{/if}"
                                        aria-label="{translate key='plugins.generic.indexingPageManager.admin.indexList.toggleActive'}"
                                        title="{translate key='plugins.generic.indexingPageManager.admin.indexList.toggleActive'}">
                                    <span class="ipm-toggle-knob"></span>
                                </button>
                                <button type="button" class="ipm-btn ipm-btn-small"
                                        data-ipm-action="open" data-ipm-verb="indexForm"
                                        data-ipm-extra-index-id="{$index->getId()|escape}">
                                    {translate key="common.edit"}
                                </button>
                                <button type="button" class="ipm-btn ipm-btn-small ipm-btn-danger"
                                        data-ipm-action="deleteIndex"
                                        data-index-id="{$index->getId()|escape}"
                                        data-index-name="{$index->getName()|escape}">
                                    {translate key="common.delete"}
                                </button>
                            </div>
                        </div>
                    {/foreach}
                    </div>
                {/if}
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
