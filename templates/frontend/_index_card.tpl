{**
 * templates/frontend/_index_card.tpl
 *
 * Indexing Page Manager — shared index tile partial.
 *
 * Required vars:
 *   $index        Index
 *   $ipmContextId int
 *   $withName     bool  (true → render the name caption)
 *   $withDesc     bool  (true → render the short description)
 *
 * Marked up as schema.org/Organization. The logo alt text + an itemprop=name
 * <meta> carry the index name even when the name caption is hidden, for
 * accessibility + SEO.
 *}
{assign var=ipmSafeUrl value=$index->getUrl()|ipm_safe_url}
<div class="ipm-card" itemscope itemtype="https://schema.org/Organization">
    {if $ipmSafeUrl}
        <a class="ipm-card-link" href="{$ipmSafeUrl|escape}" target="_blank" rel="noopener noreferrer"
           title="{$index->getName()|escape}" itemprop="url">
    {else}
        <div class="ipm-card-link ipm-card-link--static">
    {/if}

        <div class="ipm-card-logo">
            {if $index->getLogoPath()}
                {capture assign=ipmLogoSrc}{ipm_logo_url path=$index->getLogoPath() context_id=$ipmContextId}{/capture}
                <img class="ipm-card-logo-img"
                     src="{$ipmLogoSrc|escape}"
                     alt="{$index->getName()|escape}" itemprop="logo" loading="lazy">
            {else}
                <span class="ipm-card-logo-fallback" itemprop="logo">{$index->getName()|escape}</span>
            {/if}
        </div>

        {if $withName}
            <div class="ipm-card-name" itemprop="name">{$index->getName()|escape}</div>
        {else}
            <meta itemprop="name" content="{$index->getName()|escape}">
        {/if}
        {if $withDesc && $index->getDescription()}
            <div class="ipm-card-desc">{$index->getDescription()|escape}</div>
        {/if}

    {if $ipmSafeUrl}
        </a>
    {else}
        </div>
    {/if}
</div>
