<?php
/**
 * @file IndexingPageManagerSchemaMigration.php
 *
 * Indexing Page Manager — schema migration (Laravel-style, via Illuminate's
 * schema builder). Distributed under the GNU GPL v2. For full terms see the
 * file LICENSE.
 *
 * @class IndexingPageManagerSchemaMigration
 * @brief Creates the five plugin tables on enable. OJS generic plugins
 *        define their schema here; the older install.xml ADOdb format is
 *        not consulted for generic plugins. The plugin class wires this up
 *        via getInstallMigration().
 */

namespace APP\plugins\generic\indexingPageManager;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IndexingPageManagerSchemaMigration extends Migration
{
    public function up()
    {
        // 1) Indexes — main records, one row per index per journal.
        if (!Schema::hasTable('ipm_indexes')) {
            Schema::create('ipm_indexes', function (Blueprint $table) {
                $table->bigInteger('index_id')->autoIncrement();
                $table->bigInteger('journal_id');
                $table->string('logo_path', 255)->nullable();
                $table->string('url', 500)->nullable();
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['journal_id'], 'ipm_index_journal');
                $table->index(['journal_id', 'is_active'], 'ipm_index_active');
            });
        }

        // 2) Index settings — multilingual (name, description).
        // setting_type is required by OJS DAO::updateDataObjectSettings (replace/upsert).
        if (!Schema::hasTable('ipm_index_settings')) {
            Schema::create('ipm_index_settings', function (Blueprint $table) {
                $table->bigInteger('index_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->nullable()->comment('(bool|int|float|string|object)');
                $table->index(['index_id'], 'ipm_index_settings_index');
                $table->unique(['index_id', 'locale', 'setting_name'], 'ipm_index_settings_pkey');
            });
        } elseif (!Schema::hasColumn('ipm_index_settings', 'setting_type')) {
            Schema::table('ipm_index_settings', function (Blueprint $table) {
                $table->string('setting_type', 6)->nullable()->after('setting_value')->comment('(bool|int|float|string|object)');
            });
        }

        // 3) Sections — built-in seed + custom; one row per section per journal.
        if (!Schema::hasTable('ipm_sections')) {
            Schema::create('ipm_sections', function (Blueprint $table) {
                $table->bigInteger('section_id')->autoIncrement();
                $table->bigInteger('journal_id');
                $table->string('slug', 100);
                $table->tinyInteger('is_built_in')->default(0);
                $table->tinyInteger('is_active')->default(1);
                $table->integer('seq')->default(0);
                $table->dateTime('created_at');
                $table->unique(['journal_id', 'slug'], 'ipm_section_journal_slug');
                $table->index(['journal_id', 'seq'], 'ipm_section_journal_seq');
            });
        }

        // 4) Section settings — multilingual displayName.
        if (!Schema::hasTable('ipm_section_settings')) {
            Schema::create('ipm_section_settings', function (Blueprint $table) {
                $table->bigInteger('section_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->nullable()->comment('(bool|int|float|string|object)');
                $table->index(['section_id'], 'ipm_section_settings_section');
                $table->unique(['section_id', 'locale', 'setting_name'], 'ipm_section_settings_pkey');
            });
        } elseif (!Schema::hasColumn('ipm_section_settings', 'setting_type')) {
            Schema::table('ipm_section_settings', function (Blueprint $table) {
                $table->string('setting_type', 6)->nullable()->after('setting_value')->comment('(bool|int|float|string|object)');
            });
        }

        // 5) Pivot — many-to-many index<->section with per-section sequence.
        if (!Schema::hasTable('ipm_index_section')) {
            Schema::create('ipm_index_section', function (Blueprint $table) {
                $table->bigInteger('index_id');
                $table->bigInteger('section_id');
                $table->integer('seq')->default(0);
                $table->unique(['index_id', 'section_id'], 'ipm_index_section_pkey');
                $table->index(['section_id', 'seq'], 'ipm_index_section_sec_seq');
            });
        }

        // ---------------------------------------------------------------
        // 6) Foreign key constraints
        //
        // Run AFTER table creation so all referenced tables exist. Each
        // FK is wrapped in try/catch because re-running the migration on a
        // DB that already has the FK would otherwise throw, and we don't
        // want a self-healing migration to crash a page load.
        //
        // Idempotent orphan cleanup runs FIRST: any pre-existing orphan
        // rows would prevent FK creation. Cleanup is a no-op once tables
        // are healthy, so it's safe to run on every register().
        // ---------------------------------------------------------------
        $this->_convertToInnoDb();
        $this->_cleanupOrphansBeforeFk();
        $this->_addForeignKeysIfMissing();
    }

    /**
     * MySQL/MariaDB MyISAM tables silently ignore foreign keys. OJS'
     * historical default engine is MyISAM, so we proactively switch our 5
     * tables to InnoDB — idempotent because ENGINE=InnoDB on an already-
     * InnoDB table is a no-op.
     */
    private function _convertToInnoDb()
    {
        $tables = [
            'ipm_indexes',
            'ipm_index_settings',
            'ipm_sections',
            'ipm_section_settings',
            'ipm_index_section',
        ];
        $conn = DB::connection();
        foreach ($tables as $t) {
            try {
                if (!Schema::hasTable($t)) continue;
                $conn->statement("ALTER TABLE `$t` ENGINE=InnoDB");
            } catch (\Throwable $e) {
                error_log('[indexingPageManager] could not convert ' . $t . ' to InnoDB: ' . $e->getMessage());
            }
        }
    }

    /**
     * Delete orphaned rows in settings + pivot tables before attempting to
     * add foreign keys. Idempotent: subsequent runs find nothing to delete.
     */
    private function _cleanupOrphansBeforeFk()
    {
        $conn = DB::connection();
        try {
            $conn->statement(
                "DELETE FROM ipm_index_settings
                 WHERE index_id NOT IN (SELECT index_id FROM ipm_indexes)"
            );
        } catch (\Throwable $e) { /* table may not exist on a partial install */ }

        try {
            $conn->statement(
                "DELETE FROM ipm_section_settings
                 WHERE section_id NOT IN (SELECT section_id FROM ipm_sections)"
            );
        } catch (\Throwable $e) { /* ignore */ }

        try {
            $conn->statement(
                "DELETE FROM ipm_index_section
                 WHERE index_id NOT IN (SELECT index_id FROM ipm_indexes)
                    OR section_id NOT IN (SELECT section_id FROM ipm_sections)"
            );
        } catch (\Throwable $e) { /* ignore */ }
    }

    /**
     * Add the 4 foreign-key constraints that wire settings + pivot rows to
     * their parents with ON DELETE CASCADE. Each constraint is its own
     * try/catch so a single duplicate doesn't block the rest.
     */
    private function _addForeignKeysIfMissing()
    {
        try {
            if (Schema::hasTable('ipm_index_settings')) {
                Schema::table('ipm_index_settings', function (Blueprint $t) {
                    $t->foreign('index_id', 'ipm_is_index_fk')
                      ->references('index_id')->on('ipm_indexes')
                      ->onDelete('cascade');
                });
            }
        } catch (\Throwable $e) { /* already exists */ }

        try {
            if (Schema::hasTable('ipm_section_settings')) {
                Schema::table('ipm_section_settings', function (Blueprint $t) {
                    $t->foreign('section_id', 'ipm_ss_section_fk')
                      ->references('section_id')->on('ipm_sections')
                      ->onDelete('cascade');
                });
            }
        } catch (\Throwable $e) { /* already exists */ }

        try {
            if (Schema::hasTable('ipm_index_section')) {
                Schema::table('ipm_index_section', function (Blueprint $t) {
                    $t->foreign('index_id', 'ipm_xs_index_fk')
                      ->references('index_id')->on('ipm_indexes')
                      ->onDelete('cascade');
                });
            }
        } catch (\Throwable $e) { /* already exists */ }

        try {
            if (Schema::hasTable('ipm_index_section')) {
                Schema::table('ipm_index_section', function (Blueprint $t) {
                    $t->foreign('section_id', 'ipm_xs_section_fk')
                      ->references('section_id')->on('ipm_sections')
                      ->onDelete('cascade');
                });
            }
        } catch (\Throwable $e) { /* already exists */ }
    }

    public function down()
    {
        Schema::dropIfExists('ipm_index_section');
        Schema::dropIfExists('ipm_section_settings');
        Schema::dropIfExists('ipm_sections');
        Schema::dropIfExists('ipm_index_settings');
        Schema::dropIfExists('ipm_indexes');
    }
}
