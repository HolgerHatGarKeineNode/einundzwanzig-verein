<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit log of an election: one line per privileged action, only ever
     * appended, each line hashing the one before it (see `ElectionAuditLog`).
     *
     * One chain per election, numbered by `sequence`, so a test election can
     * be purged with its whole chain and no other chain is touched.
     *
     * `election_event_id` and `election_round_id` carry no foreign key on
     * purpose: a draft election or round may be deleted, and its lines — the
     * deletion line among them — must stay. Who acted is constrained, because
     * members are never deleted, only erased.
     *
     * Never a column for a ballot choice, a ballot id or a receipt. `details`
     * takes a fixed list of keys, enforced by `ElectionAuditLog::append()`.
     */
    public function up(): void
    {
        Schema::create('election_audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('election_event_id');
            $table->unsignedInteger('sequence');
            $table->string('action');
            $table->unsignedBigInteger('election_round_id')->nullable();
            $table->foreignId('actor_pleb_id')->nullable()->constrained('einundzwanzig_plebs')->restrictOnDelete();
            $table->foreignId('second_pleb_id')->nullable()->constrained('einundzwanzig_plebs')->restrictOnDelete();
            $table->foreignId('subject_pleb_id')->nullable()->constrained('einundzwanzig_plebs')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->char('previous_hash', 64);
            $table->char('hash', 64);
            $table->timestamp('created_at');

            $table->unique(['election_event_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_audit_entries');
    }
};
