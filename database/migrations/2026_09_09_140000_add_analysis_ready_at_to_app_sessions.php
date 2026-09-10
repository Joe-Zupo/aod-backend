<?php

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `app_sessions.analysis_ready_at` (issue #17): when the session last
     * reached `analysis_ready`. Stamped by Session::markAnalysisReady(), left
     * in place by reopenReview(). The coach dashboard orders its session pool
     * by this column, most recent first. Existing analysis_ready rows are
     * backfilled to `updated_at` as the closest available proxy.
     */
    public function up(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->timestamp('analysis_ready_at')->nullable()->after('status');
        });

        DB::table('app_sessions')
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->update(['analysis_ready_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->dropColumn('analysis_ready_at');
        });
    }
};
