<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message planned along an election's timeline: announcement, deadline
     * for candidacies, fee reminder, "the election starts now".
     *
     * The message is composed (and its text checked) when the row is
     * written; the audience is resolved only when `dm:send-due` starts the
     * run at `send_at`. `dm_run_id` is set in the same transaction that
     * creates the run, so a row is started at most once.
     */
    public function up(): void
    {
        Schema::create('dm_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_event_id')->constrained('election_events')->cascadeOnDelete();
            $table->foreignId('dm_message_id')->constrained()->cascadeOnDelete();
            $table->string('audience');
            $table->timestamp('send_at');
            $table->foreignId('dm_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['dm_run_id', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_schedules');
    }
};
