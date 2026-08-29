<?php
/**
 * @file classes/form/IpmTemplateForm.php
 *
 * Indexing Page Manager — display template + column selector form.
 *
 * One radio-card per template (logos / named) plus a column-count chooser
 * (3 / 4 / 5). Persists to the displayTemplate + displayColumns settings.
 */

namespace APP\plugins\generic\indexingPageManager\classes\form;

use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorInSet;

class IpmTemplateForm extends Form
{
    /** @var IndexingPageManagerPlugin */
    private $plugin;

    /** @var int */
    private $contextId;

    public function __construct($plugin, $contextId)
    {
        parent::__construct($plugin->getTemplateResource('admin/templateSelector.tpl'));
        $this->plugin = $plugin;
        $this->contextId = (int) $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorInSet($this, 'displayTemplate', 'required',
            'plugins.generic.indexingPageManager.template.error.invalid',
            IndexingPageManagerPlugin::TEMPLATES));
    }

    public function initData()
    {
        $this->setData('displayTemplate',
            IndexingPageManagerPlugin::normalizeTemplate($this->plugin->getSetting($this->contextId, 'displayTemplate')));
        $this->setData('displayColumns',
            (int) ($this->plugin->getSetting($this->contextId, 'displayColumns') ?: 4));
    }

    public function readInputData()
    {
        $this->readUserVars(['displayTemplate', 'displayColumns']);
        $cols = (int) $this->getData('displayColumns');
        if (!in_array($cols, IndexingPageManagerPlugin::COLUMN_OPTIONS, true)) {
            $cols = 4;
        }
        $this->setData('displayColumns', $cols);
    }

    public function fetch($request, $template = null, $display = false)
    {
        $tm = TemplateManager::getManager($request);
        $tm->assign([
            'pluginName'    => $this->plugin->getName(),
            'templates'     => IndexingPageManagerPlugin::TEMPLATES,
            'columnOptions' => IndexingPageManagerPlugin::COLUMN_OPTIONS,
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$args)
    {
        $this->plugin->updateSetting($this->contextId, 'displayTemplate', $this->getData('displayTemplate'), 'string');
        $this->plugin->updateSetting($this->contextId, 'displayColumns', (int) $this->getData('displayColumns'), 'int');
        parent::execute(...$args);
    }
}
