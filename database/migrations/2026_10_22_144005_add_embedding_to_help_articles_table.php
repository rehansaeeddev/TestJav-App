<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The guide's meaning as a vector, for meaning-based search. Filled by `php artisan guides:embed`.
     */
    public function up(): void
    {
        Schema::table('help_articles', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('source_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('help_articles', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
