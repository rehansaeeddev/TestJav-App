<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the bot last showed in this chat (order, products, policy), so "this order",
     * "it" or "their prices" can be understood in the next message.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('context')->nullable()->after('last_help_article_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
