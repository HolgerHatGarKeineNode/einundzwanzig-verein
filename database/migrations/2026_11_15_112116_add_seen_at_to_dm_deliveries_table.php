<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the recipient first saw this message in the portal inbox. The only
     * "seen" that is a true statement: Nostr tells nobody whether a wrap was
     * opened. Cleared by the erasure.
     */
    public function up(): void
    {
        Schema::table('dm_deliveries', function (Blueprint $table): void {
            $table->timestamp('seen_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('dm_deliveries', function (Blueprint $table): void {
            $table->dropColumn('seen_at');
        });
    }
};
