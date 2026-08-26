<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('app_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('participant_role', 32);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_participants');
    }
};
