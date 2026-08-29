<?php
/**
 * @file classes/form/IpmIndexForm.php
 *
 * Indexing Page Manager — add/edit index form.
 *
 * Handles the full index record: multilingual name + description, the
 * single-locale external URL, section assignments, the is_active flag, and
 * logo upload (posted as a multipart $_FILES['logoFile'] entry via the
 * ipmSubmitWithFiles helper — NOT through OJS' AjaxFormHandler, which drops
 * file inputs from the request body).
 */

namespace APP\plugins\generic\indexingPageManager\classes\form;

use APP\core\Application;
use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexSectionDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmLogoStore;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerUrlSanitizer;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\file\TemporaryFileManager;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorLocale;
use PKP\form\validation\FormValidatorUrl;

class IpmIndexForm extends Form
{
    /** @var IndexingPageManagerPlugin */
    private $plugin;

    /** @var int */
    private $contextId;

    /** @var int|null  Index id when editing; null when creating. */
    private $indexId;

    /** @var IpmIndex|null */
    private $index;

    /**
     * @var int|null  Section id to pre-check on the form when creating a
     *                brand-new index opened via a section's "+ Add to this
     *                section" button. Ignored when editing an existing index
     *                (its real, saved section assignments take precedence).
     */
    private $preselectSectionId;

    public function __construct($plugin, $contextId, $indexId = null, $preselectSectionId = null)
    {
        parent::__construct($plugin->getTemplateResource('admin/indexForm.tpl'));

        $this->plugin = $plugin;
        $this->contextId = (int) $contextId;
        $this->indexId = $indexId ? (int) $indexId : null;
        $this->preselectSectionId = $preselectSectionId ? (int) $preselectSectionId : null;

        if ($this->indexId) {
            /** @var IpmIndexDAO $dao */
            $dao = DAORegistry::getDAO('IpmIndexDAO');
            $this->index = $dao->getById($this->indexId, $this->contextId);
        }

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        $primaryLocale = Locale::getPrimaryLocale();
        // FormValidatorLocale expects $requiredLocale as a STRING (single
        // locale code); passing an array crashes with "Illegal offset type".
        $this->addCheck(new FormValidatorLocale(
            $this, 'name', 'required',
            'plugins.generic.indexingPageManager.admin.indexForm.error.name',
            $primaryLocale
        ));

        $this->addCheck(new FormValidator(
            $this, 'sectionIds', 'required',
            'plugins.generic.indexingPageManager.admin.indexForm.error.sections'
        ));

        $this->addCheck(new FormValidatorUrl(
            $this, 'url', 'optional',
            'plugins.generic.indexingPageManager.admin.indexForm.error.url'
        ));
    }

    public function initData()
    {
        $index = $this->index;
        if (!$index) {
            // New index opened via a section's "+ Add to this section"
            // button: pre-check that section so the admin doesn't have to
            // re-find and re-select it.
            $this->_data = [
                'isActive'   => 1,
                'sectionIds' => $this->preselectSectionId ? [$this->preselectSectionId] : [],
            ];
            return;
        }

        /** @var IpmIndexSectionDAO $pivotDao */
        $pivotDao = DAORegistry::getDAO('IpmIndexSectionDAO');
        $sectionIds = $pivotDao->getSectionIdsByIndexId($index->getId());

        $this->_data = [
            'name'        => $index->getNames(),
            'description' => $index->getDescriptions(),
            'url'         => $index->getUrl(),
            'logoPath'    => $index->getLogoPath(),
            'isActive'    => $index->getIsActive() ? 1 : 0,
            'sectionIds'  => $sectionIds,
        ];
    }

    public function readInputData()
    {
        $this->readUserVars([
            'name', 'description', 'url',
            'isActive', 'temporaryFileId', 'removeLogo',
        ]);

        // Strip dangerous URL schemes (javascript:, data:, …) before
        // persisting; also coerces bare-domain input to https://.
        $rawUrl = (string) $this->getData('url');
        if ($rawUrl !== '') {
            $this->setData('url', IndexingPageManagerUrlSanitizer::sanitize($rawUrl));
        }

        // Description is plain text — strip every HTML tag at the input
        // boundary as defence-in-depth, normalise line breaks, and cap length.
        $descs = $this->getData('description');
        if (is_array($descs)) {
            foreach ($descs as $loc => $val) {
                if (is_string($val) && $val !== '') {
                    $clean = strip_tags($val);
                    $clean = str_replace(["\r\n", "\r"], "\n", $clean);
                    if (mb_strlen($clean) > 2048) {
                        $clean = mb_substr($clean, 0, 2048);
                    }
                    $descs[$loc] = $clean;
                }
            }
            $this->setData('description', $descs);
        }

        $sectionIds = (array) Application::get()->getRequest()->getUserVar('sectionIds');
        $this->setData('sectionIds', array_values(array_filter(array_map('intval', $sectionIds))));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $supportedLocales = $this->_getSupportedLocales();

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $sections = $sectionDao->getByJournalId($this->contextId);

        $sectionOptions = [];
        foreach ($sections as $section) {
            $sectionOptions[] = [
                'id'        => $section->getId(),
                'name'      => $section->getDisplayName(),
                'isBuiltIn' => $section->getIsBuiltIn(),
            ];
        }

        $logoUrl = null;
        $logoPath = $this->getData('logoPath');
        if ($logoPath) {
            $logoUrl = IpmLogoStore::buildUrl($request, $this->contextId, $logoPath);
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName'       => $this->plugin->getName(),
            'indexId'          => $this->indexId,
            'supportedLocales' => $supportedLocales,
            'primaryLocale'    => Locale::getPrimaryLocale(),
            'localeNames'      => IndexingPageManagerPlugin::localeDisplayNames(),
            'sectionOptions'   => $sectionOptions,
            'logoUrl'          => $logoUrl,
        ]);

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $request = Application::get()->getRequest();

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        /** @var IpmIndexSectionDAO $pivotDao */
        $pivotDao = DAORegistry::getDAO('IpmIndexSectionDAO');

        $index = $this->index ?: $indexDao->newDataObject();

        if (!$index->getId()) {
            $index->setJournalId($this->contextId);
        }

        $supportedLocales = $this->_getSupportedLocales();
        foreach (['name', 'description'] as $field) {
            $values = (array) ($this->getData($field) ?? []);
            foreach ($supportedLocales as $locale) {
                $index->setData($field, isset($values[$locale]) ? $values[$locale] : null, $locale);
            }
        }

        $index->setUrl($this->_nullIfBlank($this->getData('url')));
        $index->setIsActive((int) $this->getData('isActive') ? true : false);

        $previousLogo = $index->getLogoPath();

        if ($this->getData('removeLogo')) {
            $index->setLogoPath(null);
        }

        if ($index->getId()) {
            $indexDao->updateObject($index);
        } else {
            $indexDao->insertObject($index);
        }

        // Logo handling — depends on a saved id for the filename. Primary
        // path: file came in as part of the multipart POST under `logoFile`.
        $newLogoPath = null;
        if (!empty($_FILES['logoFile']) && isset($_FILES['logoFile']['error'])
            && $_FILES['logoFile']['error'] === UPLOAD_ERR_OK) {
            $newLogoPath = IpmLogoStore::saveFromUpload(
                $this->contextId, $index->getId(), $_FILES['logoFile']
            );
            if ($newLogoPath) {
                $index->setLogoPath($newLogoPath);
                $indexDao->updateObject($index);
            }
        }

        // Backward-compat: honour an OJS TemporaryFile id if present.
        $tempFileId = (int) $this->getData('temporaryFileId');
        if (!$newLogoPath && $tempFileId) {
            $tfm = new TemporaryFileManager();
            $tempFile = $tfm->getFile($tempFileId, $request->getUser()->getId());
            if ($tempFile) {
                $newLogoPath = IpmLogoStore::saveFromTempFile(
                    $this->contextId, $index->getId(), $tempFile
                );
                if ($newLogoPath) {
                    $index->setLogoPath($newLogoPath);
                    $indexDao->updateObject($index);
                }
                $tfm->deleteById($tempFileId, $request->getUser()->getId());
            }
        }

        if ($this->getData('removeLogo') && $previousLogo) {
            IpmLogoStore::deleteByPath($this->contextId, $previousLogo);
        } elseif ($newLogoPath && $previousLogo && $previousLogo !== $index->getLogoPath()) {
            IpmLogoStore::deleteByPath($this->contextId, $previousLogo);
        }

        $pivotDao->syncSectionsForIndex(
            $index->getId(),
            (array) $this->getData('sectionIds')
        );

        parent::execute(...$functionArgs);
        return $index->getId();
    }

    private function _getSupportedLocales()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if ($context && method_exists($context, 'getSupportedFormLocales')) {
            $locales = $context->getSupportedFormLocales();
            if (!empty($locales)) {
                return $locales;
            }
        }
        $supported = Locale::getSupportedLocales();
        return $supported ? array_keys($supported) : [Locale::getPrimaryLocale()];
    }

    private function _nullIfBlank($value)
    {
        if ($value === null) return null;
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
