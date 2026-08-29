<?php
/**
 * @file classes/IpmIndexDAO.php
 *
 * Indexing Page Manager — Index DAO.
 *
 * Persists ipm_indexes + ipm_index_settings (the multilingual name +
 * description). Pivot rows (ipm_index_section) are managed by
 * IndexSectionDAO; this DAO only joins them when listing by section.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\db\DAO;
use PKP\core\Core;

class IpmIndexDAO extends DAO
{
    public function newDataObject()
    {
        return new IpmIndex();
    }

    public function getById($indexId, $journalId = null)
    {
        $sql = 'SELECT * FROM ipm_indexes WHERE index_id = ?';
        $params = [(int) $indexId];
        if ($journalId !== null) {
            $sql .= ' AND journal_id = ?';
            $params[] = (int) $journalId;
        }
        $row = $this->retrieve($sql, $params)->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    /**
     * @return IpmIndex[]
     */
    public function getByJournalId($journalId, $activeOnly = false)
    {
        $sql = 'SELECT * FROM ipm_indexes WHERE journal_id = ?';
        $params = [(int) $journalId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY index_id';

        $indexes = [];
        foreach ($this->retrieve($sql, $params) as $row) {
            $indexes[] = $this->_fromRow((array) $row, true);
        }
        $this->_eagerLoadSettings($indexes);
        return $indexes;
    }

    /**
     * Indexes attached to a section, sorted by their per-section position.
     * Inactive indexes are excluded by default — the admin grid passes
     * $activeOnly = false to surface them.
     *
     * $journalId, when given, additionally constrains the result to indexes
     * owned by that journal. The join goes through the pivot table, so
     * without this a pivot row pointing at a section in another journal would
     * leak that journal's index into this one's page. Callers that resolved
     * the section from a journal-scoped query should always pass it.
     *
     * @return IpmIndex[]
     */
    public function getBySectionId($sectionId, $activeOnly = true, $journalId = null)
    {
        $sql = 'SELECT e.*, xs.seq AS section_seq
                FROM ipm_indexes e
                INNER JOIN ipm_index_section xs ON e.index_id = xs.index_id
                WHERE xs.section_id = ?';
        $params = [(int) $sectionId];
        if ($journalId !== null) {
            $sql .= ' AND e.journal_id = ?';
            $params[] = (int) $journalId;
        }
        if ($activeOnly) {
            $sql .= ' AND e.is_active = 1';
        }
        $sql .= ' ORDER BY xs.seq, e.index_id';

        $indexes = [];
        foreach ($this->retrieve($sql, $params) as $row) {
            $indexes[] = $this->_fromRow((array) $row, true);
        }
        $this->_eagerLoadSettings($indexes);
        return $indexes;
    }

    public function countByJournalId($journalId, $activeOnly = false)
    {
        $sql = 'SELECT COUNT(*) AS n FROM ipm_indexes WHERE journal_id = ?';
        $params = [(int) $journalId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $row = $this->retrieve($sql, $params)->current();
        return $row ? (int) $row->n : 0;
    }

    public function insertObject($index)
    {
        $now = Core::getCurrentDate();
        if (!$index->getCreatedAt()) $index->setCreatedAt($now);
        $index->setUpdatedAt($now);

        $this->update(
            'INSERT INTO ipm_indexes
              (journal_id, logo_path, url, is_active, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $index->getJournalId(),
                $index->getLogoPath(),
                $index->getUrl(),
                $index->getIsActive() ? 1 : 0,
                $index->getCreatedAt(),
                $index->getUpdatedAt(),
            ]
        );

        // Defence-in-depth against a stale lastInsertId(): if the resolved id
        // is missing or its row doesn't actually belong to this journal (which
        // can happen when several indexes are inserted back-to-back in one
        // request and the PDO connection lastInsertId() reads is out of sync
        // with the one DAO::update() ran the INSERT on), fall back to the
        // newest row for this journal. Getting this wrong silently writes this
        // index's localized name/description onto a DIFFERENT index's settings
        // rows. Mirrors IpmSectionDAO::insertObject()'s natural-key fallback;
        // indexes have no natural key, so MAX(index_id) per journal is the
        // best available anchor (sound in the sequential seed loop this guards).
        $insertId = $this->getInsertId();
        $journalId = (int) $index->getJournalId();
        if (!$insertId || !$this->_idOwnsJournal($insertId, $journalId)) {
            $row = $this->retrieve(
                'SELECT MAX(index_id) AS max_id FROM ipm_indexes WHERE journal_id = ?',
                [$journalId]
            )->current();
            if ($row && $row->max_id) {
                $insertId = (int) $row->max_id;
            }
        }

        $index->setId($insertId);
        $this->updateLocaleFields($index);
        return $index->getId();
    }

    /**
     * @return bool Whether $indexId's row is owned by $journalId — a sanity
     *              check on the value returned by getInsertId().
     */
    private function _idOwnsJournal($indexId, $journalId)
    {
        $row = $this->retrieve(
            'SELECT 1 FROM ipm_indexes WHERE index_id = ? AND journal_id = ?',
            [(int) $indexId, (int) $journalId]
        )->current();
        return (bool) $row;
    }

    public function updateObject($index)
    {
        $index->setUpdatedAt(Core::getCurrentDate());

        $this->update(
            'UPDATE ipm_indexes SET
                logo_path = ?, url = ?, is_active = ?, updated_at = ?
              WHERE index_id = ?',
            [
                $index->getLogoPath(),
                $index->getUrl(),
                $index->getIsActive() ? 1 : 0,
                $index->getUpdatedAt(),
                (int) $index->getId(),
            ]
        );
        $this->updateLocaleFields($index);
    }

    /**
     * Toggle the active flag without touching anything else (admin AJAX).
     */
    public function setActive($indexId, $isActive)
    {
        $this->update(
            'UPDATE ipm_indexes SET is_active = ?, updated_at = ? WHERE index_id = ?',
            [$isActive ? 1 : 0, Core::getCurrentDate(), (int) $indexId]
        );
    }

    public function deleteObject($index)
    {
        return $this->deleteById((int) $index->getId());
    }

    public function deleteById($indexId)
    {
        $this->update('DELETE FROM ipm_index_settings WHERE index_id = ?', [(int) $indexId]);
        $this->update('DELETE FROM ipm_index_section WHERE index_id = ?', [(int) $indexId]);
        return $this->update('DELETE FROM ipm_indexes WHERE index_id = ?', [(int) $indexId]);
    }

    public function getInsertId(): int
    {
        return $this->_lastInsertId('ipm_indexes', 'index_id');
    }

    /**
     * OJS 3.5's base `PKP\db\DAO` no longer provides the legacy
     * `_getInsertId($table, $column)` helper, so this resolves the last
     * insert ID directly via the Laravel DB facade. Postgres needs the
     * sequence name; MySQL/MariaDB/SQLite just need the PDO call.
     */
    private function _lastInsertId(string $table, string $column): int
    {
        $connection = \Illuminate\Support\Facades\DB::connection();
        $pdo = $connection->getPdo();
        if ($connection->getDriverName() === 'pgsql') {
            return (int) $pdo->lastInsertId($table . '_' . $column . '_seq');
        }
        return (int) $pdo->lastInsertId();
    }

    public function getLocaleFieldNames(): array
    {
        return ['name', 'description'];
    }

    public function updateLocaleFields($index)
    {
        $this->updateDataObjectSettings(
            'ipm_index_settings',
            $index,
            ['index_id' => (int) $index->getId()]
        );
    }

    /**
     * Build an IpmIndex object from a row.
     *
     * @param array $row           Raw row from ipm_indexes
     * @param bool  $skipSettings  When true, skip the per-row locale settings
     *                             fetch — caller bulk-loads via _eagerLoadSettings.
     */
    private function _fromRow($row, $skipSettings = false)
    {
        $e = $this->newDataObject();
        $e->setId((int) $row['index_id']);
        $e->setJournalId((int) $row['journal_id']);
        $e->setLogoPath($row['logo_path']);
        $e->setUrl($row['url']);
        $e->setIsActive((bool) $row['is_active']);
        $e->setCreatedAt($row['created_at']);
        $e->setUpdatedAt($row['updated_at']);
        if (isset($row['section_seq'])) {
            $e->setSectionSeq((int) $row['section_seq']);
        }

        if (!$skipSettings) {
            $this->getDataObjectSettings(
                'ipm_index_settings',
                'index_id',
                (int) $row['index_id'],
                $e
            );
        }
        return $e;
    }

    /**
     * Eager-load locale settings for a batch of indexes in a single SQL
     * query rather than one round-trip per row (resolves the N+1 problem).
     *
     * @param IpmIndex[] $indexes
     */
    private function _eagerLoadSettings(array $indexes)
    {
        if (empty($indexes)) return;
        $ids = array_map(function ($e) { return (int) $e->getId(); }, $indexes);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $this->retrieve(
            "SELECT index_id, locale, setting_name, setting_value, setting_type
             FROM ipm_index_settings
             WHERE index_id IN ($placeholders)",
            $ids
        );

        $byIndex = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $byIndex[(int) $r['index_id']][] = $r;
        }

        foreach ($indexes as $index) {
            $eid = (int) $index->getId();
            if (!isset($byIndex[$eid])) continue;
            foreach ($byIndex[$eid] as $r) {
                $value = $r['setting_value'];
                switch ($r['setting_type'] ?? null) {
                    case 'bool':   $value = (bool) $value;  break;
                    case 'int':    $value = (int) $value;   break;
                    case 'float':  $value = (float) $value; break;
                    case 'object':
                        $decoded = json_decode((string) $value, true);
                        // allowed_classes:false — settings values are only ever
                        // arrays/scalars; never revive arbitrary objects from a
                        // DB column (object-injection guard).
                        $value = ($decoded !== null)
                            ? $decoded
                            : @unserialize((string) $value, ['allowed_classes' => false]);
                        break;
                    // 'string' or null → leave as-is
                }
                $index->setData($r['setting_name'], $value, $r['locale']);
            }
        }
    }
}
