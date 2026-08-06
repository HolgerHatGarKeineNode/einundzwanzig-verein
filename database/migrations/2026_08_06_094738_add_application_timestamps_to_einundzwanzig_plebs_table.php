<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `statutes_accepted_at` is the only evidence that someone agreed to the
     * statutes. Since the payment — not a board decision — constitutes the
     * membership, this timestamp is the document the membership rests on.
     * It was validated before (rule `accepted`) but never persisted.
     */
    public function up(): void
    {
        Schema::table('einundzwanzig_plebs', function (Blueprint $table) {
            $table->timestamp('statutes_accepted_at')->nullable();
            $table->timestamp('applied_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('einundzwanzig_plebs', function (Blueprint $table) {
            $table->dropColumn(['statutes_accepted_at', 'applied_at']);
        });
    }
};
