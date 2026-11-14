<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was chosen — one row per chosen option, never one row per ballot.
     *
     * Rows of the same ballot share nothing, so an unusual combination of
     * choices cannot serve as a signature. An empty ballot is a single row with
     * the choice `blank` and no candidate, which keeps abstentions countable.
     *
     * The id is 16 bytes from `random_bytes()`, hex encoded. Not a UUID from
     * `HasUuids` (Laravel generates UUIDv7 there, which carries the wall clock),
     * not a ULID, not an auto-increment. No timestamps, and no reference to a
     * member, a mark or a receipt. `tests/Feature/Models/BallotSecrecyTest.php`
     * pins the exact column list.
     */
    public function up(): void
    {
        Schema::create('election_ballot_choices', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('election_candidate_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('choice');

            $table->index(['election_round_id', 'election_candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_ballot_choices');
    }
};
