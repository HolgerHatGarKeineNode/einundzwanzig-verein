<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The chair's casting vote on the tie of a sealed round (statutes Art. 6):
     * whom the chair decided for, the chair's name for the minutes and an
     * optional note. One per round, written once by `RecordCastingVote`, never
     * changed. Annulling the round voids it; the row stays as a record.
     */
    public function up(): void
    {
        Schema::create('election_casting_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_round_id')->unique()->constrained()->restrictOnDelete();
            $table->json('candidate_ids');
            $table->string('chair_name', 200);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_pleb_id')->constrained('einundzwanzig_plebs')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_casting_votes');
    }
};
