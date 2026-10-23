<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The merchant's verdict on an assistant reply: "good" or "wrong". Wrong answers become test questions.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('feedback')->nullable()->after('confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('feedback');
        });
    }
};
