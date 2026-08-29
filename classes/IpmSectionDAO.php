<?php
/**
 * @file classes/IpmSectionDAO.php
 *
 * Indexing Page Manager — Section DAO.
 *
 * Built-in sections are seeded once per journal at plugin enable time and
 * their slugs are immutable. Display names are multilingual and stored in the
 * ipm_section_settings table.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\db\DAO;
use PKP\core\Core;

class IpmSectionDAO extends DAO
{
    public function newDataObject()
    {
        return new IpmSection();
    }

    public function getById($sectionId, $journalId = null)
    {
        $sql = 'SELECT * FROM ipm_sections WHERE section_id = ?';
        $params = [(int) $sectionId];
        if ($journalId !== null) {
            $sql .= ' AND journal_id = ?';
            $params[] = (int) $journalId;
        }
        $row = $this->retrieve($sql, $params)->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    public function getBySlug($slug, $journalId)
    {
        $row = $this->retrieve(
            'SELECT * FROM ipm_sections WHERE slug = ? AND journal_id = ?',
            [(string) $slug, (int) $journalId]
        )->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    /**
     * @return IpmSection[]
     */
    public function getByJournalId($journalId, $activeOnly = false)
    {
        $sql = 'SELECT * FROM ipm_sections WHERE journal_id = ?';
        $params = [(int) $journalId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY seq, section_id';

        $sections = [];
        foreach ($this->retrieve($sql, $params) as $row) {
            $sections[] = $this->_fromRow((array) $row);
        }
        return $sections;
    }

    public function getMaxSeq($journalId)
    {
        $row = $this->retrieve(
            'SELECT MAX(seq) AS max_seq FROM ipm_sections WHERE journal_id = ?',
            [(int) $journalId]
        )->current();
        return $row ? (int) $row->max_seq : 0;
    }

    public function insertObject($section)
    {
        $createdAt = $section->getCreatedAt() ?: Core::getCurrentDate();
        $section->setCreatedAt($createdAt);

        $this->update(
            'INSERT INTO ipm_sections
              (journal_id, slug, is_built_in, is_active, seq, created_at)
              VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $section->getJournalId(),
                (string) $section->getSlug(),
                $section->getIsBuiltIn() ? 1 : 0,
                $section->getIsActive() ? 1 : 0,
                $section->getSeq(),
                $createdAt,
            ]
        );

        $insertId = $this->getInsertId();
        // Defence-in-depth: (journal_id, slug) is UNIQUE (see install.xml /
        // the schema migration), so if the resolved insert id is missing or
        // clearly wrong (0, or doesn't actually own this slug — which can
        // happen if the underlying PDO/Eloquent connection's lastInsertId()
        // is out of sync with the connection DAO::update() executed the
        // INSERT on, e.g. when seeding several sections back-to-back in one
        // request), fall back to looking the row up by its natural key
        // instead of trusting a possibly-stale id. Getting this wrong here
        // silently corrupts a DIFFERENT section's settings (writes this
        // section's display name onto whatever section that stale id
        // actually belongs to) — exactly the kind of bug where only the
        // last-seeded section ends up with a visible label and the rest
        // stay blank.
        if (!$insertId || !$this->_idOwnsSlug($insertId, (string) $section->getSlug(), (int) $section->getJournalId())) {
            $resolved = $this->_findIdBySlug((string) $section->getSlug(), (int) $section->getJournalId());
            if ($resolved) {
                $insertId = $resolved;
            }
        }

        $section->setId($insertId);
        $this->updateLocaleFields($section);
        return $section->getId();
    }

    /**
     * @return bool Whether $sectionId's row actually has the given slug.
     */
    private function _idOwnsSlug($sectionId, $slug, $journalId)
    {
        $row = $this->retrieve(
            'SELECT 1 FROM ipm_sections WHERE section_id = ? AND slug = ? AND journal_id = ?',
            [(int) $sectionId, (string) $slug, (int) $journalId]
        )->current();
        return (bool) $row;
    }

    /**
     * Resolve a section's real id via its unique (journal_id, slug) natural
     * key — used as a lastInsertId() sanity check/fallback in insertObject().
     */
    private function _findIdBySlug($slug, $journalId)
    {
        $row = $this->retrieve(
            'SELECT section_id FROM ipm_sections WHERE slug = ? AND journal_id = ?',
            [(string) $slug, (int) $journalId]
        )->current();
        return $row ? (int) $row->section_id : null;
    }

    public function updateObject($section)
    {
        $this->update(
            'UPDATE ipm_sections
              SET slug = ?, is_built_in = ?, is_active = ?, seq = ?
              WHERE section_id = ?',
            [
                (string) $section->getSlug(),
                $section->getIsBuiltIn() ? 1 : 0,
                $section->getIsActive() ? 1 : 0,
                $section->getSeq(),
                (int) $section->getId(),
            ]
        );
        $this->updateLocaleFields($section);
    }

    /**
     * Built-in sections cannot be deleted; caller must check
     * $section->getIsBuiltIn() first.
     */
    public function deleteObject($section)
    {
        return $this->deleteById((int) $section->getId());
    }

    public function deleteById($sectionId)
    {
        $this->update('DELETE FROM ipm_section_settings WHERE section_id = ?', [(int) $sectionId]);
        $this->update('DELETE FROM ipm_index_section WHERE section_id = ?', [(int) $sectionId]);
        return $this->update('DELETE FROM ipm_sections WHERE section_id = ?', [(int) $sectionId]);
    }

    public function getInsertId(): int
    {
        return $this->_lastInsertId('ipm_sections', 'section_id');
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
        return ['displayName'];
    }

    public function updateLocaleFields($section)
    {
        $this->updateDataObjectSettings(
            'ipm_section_settings',
            $section,
            ['section_id' => (int) $section->getId()]
        );
    }

    private function _fromRow($row)
    {
        $section = $this->newDataObject();
        $section->setId((int) $row['section_id']);
        $section->setJournalId((int) $row['journal_id']);
        $section->setSlug($row['slug']);
        $section->setIsBuiltIn((bool) $row['is_built_in']);
        $section->setIsActive((bool) $row['is_active']);
        $section->setSeq((int) $row['seq']);
        $section->setCreatedAt($row['created_at']);

        $this->getDataObjectSettings(
            'ipm_section_settings',
            'section_id',
            (int) $row['section_id'],
            $section
        );
        return $section;
    }
}
