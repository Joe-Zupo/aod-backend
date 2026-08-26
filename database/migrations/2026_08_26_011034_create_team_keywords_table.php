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
        Schema::create('team_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_settings_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 64);
            $table->string('category', 16);
            $table->timestamps();

            $table->unique(['team_settings_id', 'keyword']);
            $table->index(['team_settings_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_keywords');
    }
};
