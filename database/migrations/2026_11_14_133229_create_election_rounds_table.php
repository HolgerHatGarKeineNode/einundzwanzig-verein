<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One ballot question of an election (presidency, board seats, auditors, a
     * run-off).
     *
     * There is no status column. Whether a round is open is derived from
     * `opened_at`, the window and the clock at the moment of reading (see
     * `ElectionRound::stateAt()`), so no job has to flip anything and a job that
     * did not run cannot leave a round open.
     *
     * `opens_at`, `closes_at` and `reveal_at` describe the window and are frozen
     * by `opened_at`. `closed_at` is the moment the round was sealed — closed for
     * good and its ballot rows rewritten — and is the only thing a tally may key
     * on.
     */
    public function up(): void
    {
        Schema::create('election_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_round_id')->nullable()->constrained('election_rounds')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->string('mode');
            $table->string('counting_rule');
            $table->unsignedInteger('seats');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('reveal_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('annulled_at')->nullable();
            $table->timestamps();

            $table->unique(['election_event_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_rounds');
    }
};
