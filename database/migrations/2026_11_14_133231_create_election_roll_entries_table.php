<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The electoral roll of a round: a snapshot written when the round opens.
     *
     * The right to vote is read from here and from nowhere else — not from the
     * live payment state, which flips both ways while a round is open. `reason`
     * says why the member is entitled, `source` how the row got here (opening,
     * late payment, inherited by a run-off). Rows are only ever added.
     *
     * Timestamps are fine on this table: who MAY vote is not secret. Who DID
     * vote is, and that lives in `election_marks`.
     */
    public function up(): void
    {
        Schema::create('election_roll_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->string('source');
            $table->timestamps();

            $table->unique(['election_round_id', 'einundzwanzig_pleb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_roll_entries');
    }
};
