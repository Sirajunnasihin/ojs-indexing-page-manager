{**
 * templates/frontend/template-grid.tpl
 *
 * Single grid renderer for all four display templates. What appears under each
 * logo is controlled by the booleans passed from the handler:
 *   $ipmShowName  → render the index name
 *   $ipmShowDesc  → render the short description
 * giving: logos only / logo+name / logo+name+desc / logo+description.
 *
 * Each section is always shown in full (no collapse/expand). Column count comes
 * from the --ipm-cols custom property set inline on each grid.
 *}
{extends file=$ipmLayoutPath}

{block name="ipmContent"}
<div class="ipm-collection">
    {foreach from=$ipmBlocks item=block}
        {assign var=section value=$block.section}
        {assign var=indexes value=$block.indexes}

        <section class="ipm-section">
            <div class="ipm-section-header">
                <h2 class="ipm-section-title">{$section->getDisplayName()|escape}</h2>
                <span class="ipm-section-count">{$indexes|@count|escape}</span>
            </div>

            <div class="ipm-grid {if $ipmShowName || $ipmShowDesc}ipm-grid--captioned{else}ipm-grid--logos{/if}"
                 style="--ipm-cols:{$ipmColumns|escape}">
                {foreach from=$indexes item=index}
                    {include file="`$ipmTplPrefix`frontend/_index_card.tpl"
                             index=$index withName=$ipmShowName withDesc=$ipmShowDesc}
                {/foreach}
            </div>
        </section>
    {/foreach}
</div>
{/block}
