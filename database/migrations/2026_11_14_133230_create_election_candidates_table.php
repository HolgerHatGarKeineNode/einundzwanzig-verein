<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who stands in a round. Candidates belong to the round, not to the
     * election: a run-off lists two of them, and whoever is elected in one round
     * no longer stands in the next.
     */
    public function up(): void
    {
        Schema::create('election_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['election_round_id', 'einundzwanzig_pleb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_candidates');
    }
};
