<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which parts produced a reply ("Laya → RAG → LLM") and how sure each was, shown under the reply.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('answered_by')->nullable()->after('help_url');
            $table->json('confidence')->nullable()->after('answered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['answered_by', 'confidence']);
        });
    }
};
