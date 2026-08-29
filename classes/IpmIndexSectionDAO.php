<?php
/**
 * @file classes/IpmIndexSectionDAO.php
 *
 * Indexing Page Manager — pivot DAO (index <-> section many-to-many).
 *
 * The same index can appear in multiple sections with independent positions
 * in each (e.g. EBSCO under both Abstracting & Indexing and Discovery
 * Services). Index and Section DAOs delete pivot rows via CASCADE/manual
 * DELETE; this DAO is for assignment + ordering operations.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\db\DAO;

class IpmIndexSectionDAO extends DAO
{
    /**
     * Replace the entire section set for an index.
     *
     * @param int   $indexId
     * @param int[] $sectionIds  New section set (the index is appended to the
     *                           end of each).
     */
    public function syncSectionsForIndex($indexId, array $sectionIds)
    {
        $indexId = (int) $indexId;
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));

        $existing = $this->getSectionIdsByIndexId($indexId);

        $toRemove = array_diff($existing, $sectionIds);
        $toAdd    = array_diff($sectionIds, $existing);

        foreach ($toRemove as $sectionId) {
            $this->detach($indexId, (int) $sectionId);
        }
        foreach ($toAdd as $sectionId) {
            $this->attach($indexId, (int) $sectionId);
        }
    }

    /**
     * Add an index to a section, appending it to the end of the existing
     * order. No-op if the row already exists.
     */
    public function attach($indexId, $sectionId)
    {
        $indexId = (int) $indexId;
        $sectionId = (int) $sectionId;

        $existing = $this->retrieve(
            'SELECT 1 FROM ipm_index_section WHERE index_id = ? AND section_id = ?',
            [$indexId, $sectionId]
        )->current();
        if ($existing) {
            return;
        }

        $maxRow = $this->retrieve(
            'SELECT MAX(seq) AS max_seq FROM ipm_index_section WHERE section_id = ?',
            [$sectionId]
        )->current();
        $nextSeq = $maxRow ? ((int) $maxRow->max_seq) + 1 : 0;

        $this->update(
            'INSERT INTO ipm_index_section (index_id, section_id, seq) VALUES (?, ?, ?)',
            [$indexId, $sectionId, $nextSeq]
        );
    }

    public function detach($indexId, $sectionId)
    {
        $this->update(
            'DELETE FROM ipm_index_section WHERE index_id = ? AND section_id = ?',
            [(int) $indexId, (int) $sectionId]
        );
    }

    public function detachAllForIndex($indexId)
    {
        $this->update('DELETE FROM ipm_index_section WHERE index_id = ?', [(int) $indexId]);
    }

    public function detachAllForSection($sectionId)
    {
        $this->update('DELETE FROM ipm_index_section WHERE section_id = ?', [(int) $sectionId]);
    }

    /**
     * Persist a new ordering for indexes inside a single section. Any index id
     * in the input that isn't already attached is silently skipped.
     *
     * @param int   $sectionId
     * @param int[] $indexIdsInOrder  New display order, first to last.
     */
    public function reorderIndexesInSection($sectionId, array $indexIdsInOrder)
    {
        $sectionId = (int) $sectionId;
        $existingIds = $this->getIndexIdsBySectionId($sectionId);

        $seq = 0;
        foreach ($indexIdsInOrder as $indexId) {
            $indexId = (int) $indexId;
            if (!in_array($indexId, $existingIds, true)) {
                continue;
            }
            $this->update(
                'UPDATE ipm_index_section SET seq = ? WHERE index_id = ? AND section_id = ?',
                [$seq++, $indexId, $sectionId]
            );
        }
    }

    /**
     * @return int[]
     */
    public function getSectionIdsByIndexId($indexId)
    {
        $ids = [];
        foreach ($this->retrieve(
            'SELECT section_id FROM ipm_index_section WHERE index_id = ?',
            [(int) $indexId]
        ) as $row) {
            $ids[] = (int) $row->section_id;
        }
        return $ids;
    }

    /**
     * @return int[]
     */
    public function getIndexIdsBySectionId($sectionId)
    {
        $ids = [];
        foreach ($this->retrieve(
            'SELECT index_id FROM ipm_index_section WHERE section_id = ? ORDER BY seq, index_id',
            [(int) $sectionId]
        ) as $row) {
            $ids[] = (int) $row->index_id;
        }
        return $ids;
    }

    public function countIndexesInSection($sectionId, $activeOnly = true)
    {
        $sql = 'SELECT COUNT(*) AS n
                FROM ipm_index_section xs
                INNER JOIN ipm_indexes e ON xs.index_id = e.index_id
                WHERE xs.section_id = ?';
        $params = [(int) $sectionId];
        if ($activeOnly) {
            $sql .= ' AND e.is_active = 1';
        }
        $row = $this->retrieve($sql, $params)->current();
        return $row ? (int) $row->n : 0;
    }
}
