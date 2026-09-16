<?php

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `app_sessions.started_at` (issue #24): when the current run began.
     * Stamped by Session::start(), cleared when the session returns to
     * `queuing` (docs/adr/0015-end-of-run-and-end-of-participation.md).
     *
     * A session in a run right now is backfilled from `updated_at`, which
     * nothing writes during a run, so it still holds the moment the run
     * started. Finished sessions stay null: their `updated_at` has moved on.
     */
    public function up(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('status');
        });

        DB::table('app_sessions')
            ->where('status', Session::STATUS_IN_PROGRESS)
            ->update(['started_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
