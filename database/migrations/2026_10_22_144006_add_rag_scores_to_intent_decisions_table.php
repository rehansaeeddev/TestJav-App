<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How sure the guide search and the LLM were, next to Laya's confidence.
     */
    public function up(): void
    {
        Schema::table('intent_decisions', function (Blueprint $table) {
            $table->string('answered_by')->nullable()->after('target');
            $table->float('search_score')->nullable()->after('laya_ms');
            $table->float('llm_confidence')->nullable()->after('search_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intent_decisions', function (Blueprint $table) {
            $table->dropColumn(['answered_by', 'search_score', 'llm_confidence']);
        });
    }
};
