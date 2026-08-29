<?php
/**
 * @file classes/IpmIndex.php
 *
 * Indexing Page Manager — Index data object.
 *
 * Represents a single indexing database / discovery service / publishing
 * platform entry.
 *
 * Multilingual fields: name, description.
 * Single-locale fields: logoPath, url.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\core\DataObject;

class IpmIndex extends DataObject
{
    public function getJournalId()    { return $this->getData('journalId'); }
    public function setJournalId($v)  { $this->setData('journalId', $v); }

    public function getLogoPath()     { return $this->getData('logoPath'); }
    public function setLogoPath($v)   { $this->setData('logoPath', $v); }

    public function getUrl()          { return $this->getData('url'); }
    public function setUrl($v)        { $this->setData('url', $v); }

    public function getIsActive()     { return (bool) $this->getData('isActive'); }
    public function setIsActive($v)   { $this->setData('isActive', $v ? 1 : 0); }

    public function getCreatedAt()    { return $this->getData('createdAt'); }
    public function setCreatedAt($v)  { $this->setData('createdAt', $v); }

    public function getUpdatedAt()    { return $this->getData('updatedAt'); }
    public function setUpdatedAt($v)  { $this->setData('updatedAt', $v); }

    /**
     * Pivot-only field, populated by IndexDAO::getBySectionId(). Stores the
     * index's ordering position within a specific section.
     */
    public function getSectionSeq()   { return $this->getData('sectionSeq'); }
    public function setSectionSeq($v) { $this->setData('sectionSeq', $v); }

    // Multilingual ----------------------------------------------------------

    public function getName($locale = null)        { return $this->getLocalizedData('name', $locale); }
    public function getDescription($locale = null) { return $this->getLocalizedData('description', $locale); }

    public function getNames()        { return $this->getData('name')        ?: []; }
    public function getDescriptions() { return $this->getData('description') ?: []; }

    public function setName($v, $locale)        { $this->setData('name',        $v, $locale); }
    public function setDescription($v, $locale) { $this->setData('description', $v, $locale); }
}
