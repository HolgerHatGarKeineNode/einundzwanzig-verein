<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Has voted" — the existence of the row is the whole statement.
     *
     * The unique index is the lock against a double vote: the cast inserts here
     * first, and a second insert for the same member fails in the database no
     * matter how many requests race.
     *
     * Deliberately absent, each for the same reason — it would pair a member
     * with a ballot: timestamps (name plus time is the link when thirteen people
     * vote), an auto-increment id (it is the cast order), and any reference to a
     * ballot or a receipt. `tests/Feature/Models/BallotSecrecyTest.php` pins the
     * exact column list.
     */
    public function up(): void
    {
        Schema::create('election_marks', function (Blueprint $table): void {
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->restrictOnDelete();

            $table->unique(['election_round_id', 'einundzwanzig_pleb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_marks');
    }
};
