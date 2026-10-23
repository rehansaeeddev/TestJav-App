<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 keyword index over help_articles. The schema builder cannot create
 * virtual tables, so this uses raw statements. Triggers keep the index in sync on
 * every insert, update and delete. Other drivers would use whereFullText() instead.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("
            CREATE VIRTUAL TABLE help_articles_fts USING fts5(
                title, keywords, page_summary, steps,
                content='help_articles', content_rowid='id', tokenize='porter unicode61'
            )
        ");

        DB::statement('
            CREATE TRIGGER help_articles_ai AFTER INSERT ON help_articles BEGIN
                INSERT INTO help_articles_fts(rowid, title, keywords, page_summary, steps)
                VALUES (new.id, new.title, new.keywords, new.page_summary, new.steps);
            END
        ');

        DB::statement("
            CREATE TRIGGER help_articles_ad AFTER DELETE ON help_articles BEGIN
                INSERT INTO help_articles_fts(help_articles_fts, rowid, title, keywords, page_summary, steps)
                VALUES ('delete', old.id, old.title, old.keywords, old.page_summary, old.steps);
            END
        ");

        DB::statement("
            CREATE TRIGGER help_articles_au AFTER UPDATE ON help_articles BEGIN
                INSERT INTO help_articles_fts(help_articles_fts, rowid, title, keywords, page_summary, steps)
                VALUES ('delete', old.id, old.title, old.keywords, old.page_summary, old.steps);
                INSERT INTO help_articles_fts(rowid, title, keywords, page_summary, steps)
                VALUES (new.id, new.title, new.keywords, new.page_summary, new.steps);
            END
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS help_articles_ai');
        DB::statement('DROP TRIGGER IF EXISTS help_articles_ad');
        DB::statement('DROP TRIGGER IF EXISTS help_articles_au');
        DB::statement('DROP TABLE IF EXISTS help_articles_fts');
    }
};
