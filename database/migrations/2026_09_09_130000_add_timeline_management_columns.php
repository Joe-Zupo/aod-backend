<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timeline management (docs/adr/0010-timeline-management.md). Each timestamp
     * table gains review state (`reviewed_at` / `reviewed_by`) and authorship
     * (`created_by`, null = system-generated). The two detection-run snapshot
     * columns become nullable so a coach-created row can carry null there.
     * `annotations` gains `parent_id` for one-level reply threads.
     */
    public function up(): void
    {
        foreach (['comm_events', 'game_events', 'dead_air_periods'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('reviewed_at')->nullable()->after('id');
                $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            });
        }

        Schema::table('comm_events', function (Blueprint $table) {
            $table->unsignedInteger('padding_ms')->nullable()->change();
        });

        Schema::table('dead_air_periods', function (Blueprint $table) {
            $table->unsignedInteger('dead_air_threshold_ms')->nullable()->change();
        });

        Schema::table('annotations', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('annotations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });

        foreach (['comm_events', 'game_events', 'dead_air_periods'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('reviewed_by');
                $table->dropConstrainedForeignId('created_by');
                $table->dropColumn('reviewed_at');
            });
        }
    }
};
