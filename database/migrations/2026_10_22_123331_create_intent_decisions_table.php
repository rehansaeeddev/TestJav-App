<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per question the bot routed, so a wrong answer can be traced to the layer
     * that caused it: a rule, Laya, or a fallback.
     */
    public function up(): void
    {
        Schema::create('intent_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('part')->default(0);
            $table->text('question');
            $table->string('final_intent')->nullable();
            $table->string('decided_by');
            $table->string('target')->nullable();
            $table->string('laya_choice')->nullable();
            $table->float('laya_confidence')->nullable();
            $table->json('laya_probabilities')->nullable();
            $table->unsignedInteger('laya_ms')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intent_decisions');
    }
};
