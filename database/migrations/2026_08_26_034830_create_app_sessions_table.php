<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named app_sessions, not sessions: the latter is Laravel's own
     * SESSION_DRIVER=database table (see 0001_01_01_000000_create_users_table.php),
     * unrelated to this app's domain Session entity.
     */
    public function up(): void
    {
        Schema::create('app_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('session_name');
            $table->string('status', 16)->default('queuing');
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_sessions');
    }
};
