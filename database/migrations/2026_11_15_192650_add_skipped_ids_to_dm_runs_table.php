<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The members a run left out because they got the same message for the
 * same audience within the repeat lock (App\Services\Dm\DirectMessageRepeatLock):
 * ids only, like `recipient_ids`. Null for runs from before the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_runs', function (Blueprint $table): void {
            $table->json('skipped_ids')->nullable()->after('recipient_count');
        });
    }

    public function down(): void
    {
        Schema::table('dm_runs', function (Blueprint $table): void {
            $table->dropColumn('skipped_ids');
        });
    }
};
