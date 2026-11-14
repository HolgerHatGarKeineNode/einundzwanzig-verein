<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One vote of one member in one round, as the member signed it.
     *
     * `event` is the whole signed Nostr event (`App\Services\Election\VoteEvent`),
     * never sent to a relay. `choices` is what the event says, candidate id =>
     * choice, empty for a blank vote; `pubkey` is the key that signed it.
     * `election:verify` checks every row against its event again.
     *
     * The unique index is the lock against a double vote: the cast inserts
     * without asking first, and a second insert for the same member fails in
     * the database no matter how many requests race.
     */
    public function up(): void
    {
        Schema::create('election_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->restrictOnDelete();
            $table->char('pubkey', 64);
            $table->char('event_id', 64);
            $table->json('event');
            $table->json('choices');
            $table->timestamps();

            $table->unique(['election_round_id', 'einundzwanzig_pleb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_votes');
    }
};
