<?php
/**
 * @file classes/form/IpmSectionForm.php
 *
 * Indexing Page Manager — section add/edit form.
 *
 * Built-in sections can be renamed (per-locale display name) and toggled
 * active/inactive but their slug is locked. Custom sections accept a slug
 * auto-derived from the display name, unique within the journal.
 */

namespace APP\plugins\generic\indexingPageManager\classes\form;

use APP\core\Application;
use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\plugins\generic\indexingPageManager\classes\IpmSectionDAO;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorLocale;
use PKP\form\validation\FormValidatorRegExp;

class IpmSectionForm extends Form
{
    /** @var IndexingPageManagerPlugin */
    private $plugin;

    /** @var int */
    private $contextId;

    /** @var int|null */
    private $sectionId;

    /** @var IpmSection|null */
    private $section;

    public function __construct($plugin, $contextId, $sectionId = null)
    {
        parent::__construct($plugin->getTemplateResource('admin/sectionForm.tpl'));

        $this->plugin = $plugin;
        $this->contextId = (int) $contextId;
        $this->sectionId = $sectionId ? (int) $sectionId : null;

        if ($this->sectionId) {
            /** @var IpmSectionDAO $dao */
            $dao = DAORegistry::getDAO('IpmSectionDAO');
            $this->section = $dao->getById($this->sectionId, $this->contextId);
        }

        $primary = Locale::getPrimaryLocale();

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorLocale($this, 'displayName', 'required',
            'plugins.generic.indexingPageManager.admin.sectionForm.error.displayName',
            $primary));

        $this->addCheck(new FormValidatorRegExp($this, 'slug', 'required',
            'plugins.generic.indexingPageManager.admin.sectionForm.error.slug',
            '/^[a-z0-9][a-z0-9-]{0,99}$/'));
    }

    public function initData()
    {
        if ($this->section) {
            $this->_data = [
                'displayName' => $this->section->getDisplayNames(),
                'slug'        => $this->section->getSlug(),
                'isActive'    => $this->section->getIsActive() ? 1 : 0,
                'isBuiltIn'   => $this->section->getIsBuiltIn() ? 1 : 0,
            ];
        } else {
            $this->_data = ['isActive' => 1, 'isBuiltIn' => 0];
        }
    }

    public function readInputData()
    {
        $this->readUserVars(['displayName', 'slug', 'isActive']);

        // Built-in slug is immutable — restore the original on save attempt.
        if ($this->section && $this->section->getIsBuiltIn()) {
            $this->setData('slug', $this->section->getSlug());
            return;
        }

        // Derive the slug from the primary-locale display name when the admin
        // left it blank, so they don't have to think about URL slugs.
        $slug = trim((string) $this->getData('slug'));
        if ($slug === '') {
            $names   = (array) $this->getData('displayName');
            $primary = Locale::getPrimaryLocale();
            $source  = $names[$primary] ?? '';
            if ($source === '') {
                foreach ($names as $v) { if ($v) { $source = $v; break; } }
            }
            $slug = $source;
        }

        // Normalise to the slug charset AND guarantee (journal_id, slug)
        // uniqueness for every path — typed or derived. Previously a
        // hand-edited duplicate slug skipped _uniqueSlug() entirely and hit
        // the UNIQUE constraint on save, throwing an uncaught exception (500)
        // instead of a validation error. slugify() always yields a value the
        // RegExp validator accepts; _uniqueSlug() is a no-op when the slug is
        // unchanged for the section being edited.
        $slug = self::slugify($slug);
        $slug = $this->_uniqueSlug($slug);
        $this->setData('slug', $slug);
    }

    /**
     * Convert an arbitrary string into a slug-safe identifier: lowercase ASCII
     * letters/digits/hyphens, trimmed, max 100 chars. A direct char-map
     * handles Turkish + common European accents reliably (iconv is skipped —
     * its Windows behaviour is locale-dependent and noisy).
     */
    public static function slugify($text)
    {
        $text = (string) $text;
        $text = mb_strtolower($text, 'UTF-8');
        $map = [
            'ç'=>'c', 'ğ'=>'g', 'ı'=>'i', 'i̇'=>'i', 'ö'=>'o', 'ş'=>'s', 'ü'=>'u',
            'â'=>'a', 'à'=>'a', 'á'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'ae',
            'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
            'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i',
            'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ø'=>'o',
            'ù'=>'u', 'ú'=>'u', 'û'=>'u',
            'ñ'=>'n', 'ý'=>'y', 'ÿ'=>'y', 'ß'=>'ss',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
        $text = trim((string) $text, '-');
        if ($text === '' || !preg_match('/^[a-z0-9]/', $text)) {
            $text = 'section-' . substr(md5(microtime(true)), 0, 6);
        }
        return substr($text, 0, 100);
    }

    /**
     * Ensure $slug isn't already used by another section in the same journal,
     * appending -2, -3, … until unique. Reserved built-in slugs are also
     * avoided for new custom sections.
     */
    private function _uniqueSlug($slug)
    {
        /** @var IpmSectionDAO $dao */
        $dao = DAORegistry::getDAO('IpmSectionDAO');
        $reserved = IndexingPageManagerPlugin::getBuiltInSlugs();
        $candidate = $slug;
        $i = 2;
        while (true) {
            $clash = in_array($candidate, $reserved, true);
            if (!$clash) {
                $existing = $dao->getBySlug($candidate, $this->contextId);
                if (!$existing || ($this->section && $existing->getId() === $this->section->getId())) {
                    return $candidate;
                }
            }
            $candidate = $slug . '-' . $i;
            if ($i++ > 50) return $candidate;
        }
    }

    public function fetch($request, $template = null, $display = false)
    {
        $tm = TemplateManager::getManager($request);
        $tm->assign([
            'pluginName'       => $this->plugin->getName(),
            'sectionId'        => $this->sectionId,
            'slug'             => $this->section ? $this->section->getSlug() : null,
            'isBuiltIn'        => $this->section ? $this->section->getIsBuiltIn() : false,
            'supportedLocales' => $this->_supportedLocales(),
            'primaryLocale'    => Locale::getPrimaryLocale(),
            'localeNames'      => IndexingPageManagerPlugin::localeDisplayNames(),
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$args)
    {
        /** @var IpmSectionDAO $dao */
        $dao = DAORegistry::getDAO('IpmSectionDAO');

        $section = $this->section ?: $dao->newDataObject();

        if (!$section->getId()) {
            $section->setJournalId($this->contextId);
            $section->setIsBuiltIn(false);
            $section->setSeq($dao->getMaxSeq($this->contextId) + 1);
        }

        $section->setSlug($this->getData('slug'));
        $section->setIsActive((int) $this->getData('isActive') ? true : false);

        $values = (array) $this->getData('displayName');
        foreach ($this->_supportedLocales() as $locale) {
            $section->setData('displayName', isset($values[$locale]) ? $values[$locale] : null, $locale);
        }

        if ($section->getId()) {
            $dao->updateObject($section);
        } else {
            $dao->insertObject($section);
        }

        parent::execute(...$args);
        return $section->getId();
    }

    private function _supportedLocales()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if ($context && method_exists($context, 'getSupportedFormLocales')) {
            $locales = $context->getSupportedFormLocales();
            if (!empty($locales)) return $locales;
        }
        $supported = Locale::getSupportedLocales();
        return $supported ? array_keys($supported) : [Locale::getPrimaryLocale()];
    }
}
