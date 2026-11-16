<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a round looked like the moment it was sealed: the counting rule, the
     * numbers per option, the size of the roll, the votes cast and a hash over
     * their sorted event ids — as one canonical JSON text, and its SHA-256.
     *
     * Written once by `CloseRound`, never changed. `php artisan election:verify`
     * recomputes the text from the vote table and compares the hashes.
     */
    public function up(): void
    {
        Schema::create('election_manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_round_id')->unique()->constrained()->restrictOnDelete();
            $table->char('hash', 64);
            $table->longText('body');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_manifests');
    }
};
