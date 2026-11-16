<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `last_login_at`: the last time the member signed in with Nostr. The
     * board's electoral roll shows who has signed in since the election was
     * announced — whoever has not may still struggle with their signer, and
     * the vote is cast with it.
     */
    public function up(): void
    {
        Schema::table('einundzwanzig_plebs', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('einundzwanzig_plebs', function (Blueprint $table): void {
            $table->dropColumn('last_login_at');
        });
    }
};
