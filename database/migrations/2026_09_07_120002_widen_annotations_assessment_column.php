<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The alignment assessment values became `possibly_positive` /
     * `possibly_negative` / `neutral` (see docs/adr/0008-game-state-alignment.md,
     * 2026-09-07 amendment), which no longer fit the original `varchar(16)`. New
     * installs get 32 straight from the create migration; this catches up a
     * database that already ran it.
     */
    public function up(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->string('assessment', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->string('assessment', 16)->nullable()->change();
        });
    }
};
