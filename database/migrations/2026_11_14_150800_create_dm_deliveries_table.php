<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One recipient of one run.
     *
     * The unique index on (run, member) is what keeps a repeated fan-out from
     * addressing anyone twice; the fan-out inserts with "ignore on conflict"
     * and relies on it.
     *
     * `plaintext`, `rumor_id`, `rumor_created_at` and `wrap` are written once,
     * before the first publish, and never rebuilt: a retry republishes the
     * stored wrap, so every relay sees the same event id and every client the
     * same message. The erasure (MembershipService::erasePersonalData) clears
     * them — the wrap carries the recipient's pubkey in its `p` tag.
     *
     * `claimed_at` belongs to the atomic claim in SendDirectMessage: a claim
     * older than the job's lifetime is taken to be from a worker that died.
     */
    public function up(): void
    {
        Schema::create('dm_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dm_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->text('plaintext')->nullable();
            $table->char('rumor_id', 64)->nullable();
            $table->unsignedBigInteger('rumor_created_at')->nullable();
            $table->json('wrap')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['dm_run_id', 'einundzwanzig_pleb_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_deliveries');
    }
};
