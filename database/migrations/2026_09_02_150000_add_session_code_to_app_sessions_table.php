<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Session gets a human-facing code: `SESSION_` followed by its id,
 * zero-padded to at least three digits (SESSION_001, SESSION_048, SESSION_1234).
 * It is set by a Session `created` model hook right after insert, since the id
 * is not known until then; the column is therefore nullable at the DB level but
 * always populated in practice (and unique). Existing rows are backfilled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->string('session_code', 32)->nullable()->unique()->after('session_name');
        });

        DB::table('app_sessions')->whereNull('session_code')->orderBy('id')->get(['id'])
            ->each(fn ($row) => DB::table('app_sessions')
                ->where('id', $row->id)
                ->update(['session_code' => 'SESSION_'.str_pad((string) $row->id, 3, '0', STR_PAD_LEFT)]));
    }

    public function down(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->dropUnique(['session_code']);
            $table->dropColumn('session_code');
        });
    }
};
