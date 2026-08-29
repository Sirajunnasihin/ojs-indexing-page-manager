<?php
/**
 * @file classes/IpmSection.php
 *
 * Indexing Page Manager — Section data object.
 *
 * Represents a grouping (Abstracting & Indexing, Discovery Services,
 * Publishing Systems). Built-in sections are seeded on plugin enable; custom
 * ones are added by the admin. Display name is multilingual; slug is canonical
 * and never changes.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\core\DataObject;

class IpmSection extends DataObject
{
    public function getJournalId()    { return $this->getData('journalId'); }
    public function setJournalId($v)  { $this->setData('journalId', $v); }

    public function getSlug()         { return $this->getData('slug'); }
    public function setSlug($v)       { $this->setData('slug', $v); }

    public function getIsBuiltIn()    { return (bool) $this->getData('isBuiltIn'); }
    public function setIsBuiltIn($v)  { $this->setData('isBuiltIn', $v ? 1 : 0); }

    public function getIsActive()     { return (bool) $this->getData('isActive'); }
    public function setIsActive($v)   { $this->setData('isActive', $v ? 1 : 0); }

    public function getSeq()          { return (int) $this->getData('seq'); }
    public function setSeq($v)        { $this->setData('seq', (int) $v); }

    public function getCreatedAt()    { return $this->getData('createdAt'); }
    public function setCreatedAt($v)  { $this->setData('createdAt', $v); }

    /**
     * Multilingual display name. Returns the value for the requested locale,
     * or the active UI locale if $locale is null, with fallback to any
     * available locale.
     */
    public function getDisplayName($locale = null)
    {
        return $this->getLocalizedData('displayName', $locale);
    }

    public function getDisplayNames()
    {
        return $this->getData('displayName') ?: [];
    }

    public function setDisplayName($value, $locale)
    {
        $this->setData('displayName', $value, $locale);
    }
}
