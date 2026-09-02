<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add nullable first so the column can exist on a populated table, then
        // backfill every current row to `ready` (the state an already-running
        // session's participants are effectively in), then tighten to NOT NULL
        // with no DB default: the status is always set explicitly at join time.
        Schema::table('session_participants', function (Blueprint $table) {
            $table->string('participant_status', 16)->nullable()->after('participant_role');
        });

        DB::table('session_participants')->update(['participant_status' => 'ready']);

        Schema::table('session_participants', function (Blueprint $table) {
            $table->string('participant_status', 16)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropColumn('participant_status');
        });
    }
};
