<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('staff_user_id')->nullable();
            $table->string('awaiting')->nullable();
            $table->string('pending_intent')->nullable();
            $table->foreignId('last_help_article_id')->nullable()->constrained('help_articles')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'staff_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
