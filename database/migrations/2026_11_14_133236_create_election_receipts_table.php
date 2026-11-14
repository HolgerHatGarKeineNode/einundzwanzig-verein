<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof that a ballot was taken in — and of nothing else.
     *
     * The voter's browser makes up a code; only its SHA-256, bound to the round,
     * is stored. The row references neither the member nor the ballot, so it
     * cannot show what was chosen, and it carries no timestamp and no
     * auto-increment id. `tests/Feature/Models/BallotSecrecyTest.php` pins the
     * exact column list.
     */
    public function up(): void
    {
        Schema::create('election_receipts', function (Blueprint $table): void {
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->char('hash', 64);

            $table->unique(['election_round_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_receipts');
    }
};
