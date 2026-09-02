<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Communication Event Padding row was seeded as `comm_event_padding_ms`.
 * The `_ms` suffix is dropped so setting_name is a unit-free key matching
 * `dead_air_threshold`; the stored value stays milliseconds. Rename existing
 * rows so Team::ensureSettings() keeps finding them instead of seeding a
 * duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_settings')
            ->where('setting_name', 'comm_event_padding_ms')
            ->update(['setting_name' => 'comm_event_padding']);
    }

    public function down(): void
    {
        DB::table('team_settings')
            ->where('setting_name', 'comm_event_padding')
            ->update(['setting_name' => 'comm_event_padding_ms']);
    }
};
