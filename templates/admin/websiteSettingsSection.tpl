{**
 * templates/admin/websiteSettingsSection.tpl
 *
 * Appended (never inserted mid-markup) to the Settings > Website page via
 * the Template::Settings::website hook — see
 * IndexingPageManagerPlugin::injectWebsiteSettingsTab() for why this is
 * deliberately its own clearly-delineated section rather than an attempt at
 * a native jQuery-UI tab.
 *}
<div class="ipm-website-settings-section" style="margin-top:2.5rem;padding-top:1.5rem;border-top:2px solid #d1d5db;">
    <h3 style="margin:0 0 .25rem;font-size:1.1rem;">
        {translate key="plugins.generic.indexingPageManager.name"}
    </h3>
    <p style="margin:0 0 1rem;color:#6b7280;font-size:.9rem;">
        {translate key="plugins.generic.indexingPageManager.description"}
    </p>
    {$ipmEmbeddedShellHtml nofilter}
</div>
