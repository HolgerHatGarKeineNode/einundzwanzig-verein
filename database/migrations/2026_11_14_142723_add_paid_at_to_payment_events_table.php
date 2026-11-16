<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moment a fee was settled — written by `MembershipService::markPaid()`
     * when `paid` turns from false to true, and by nothing else.
     *
     * `updated_at` cannot answer that question: any later write to the row
     * (a redelivered webhook, an admin edit, a status refresh) moves it, and
     * the electoral roll asks precisely "was this paid before or after the
     * round opened?".
     *
     * Data step: every row that is already paid gets its `updated_at` as
     * `paid_at`. That is the best value the table holds for those rows —
     * `markPaid()` was the last writer of a paid row until now — and it is
     * the value the twelve-month fee rule read before this column existed,
     * so nobody's entitlement changes by the migration. Done in chunks by id
     * so a large table is not locked in one statement. Unpaid rows keep
     * null; `down()` drops the column and with it the backfill.
     */
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('paid');
        });

        DB::table('payment_events')
            ->where('paid', true)
            ->whereNull('paid_at')
            ->select('id')
            ->chunkById(500, function ($rows): void {
                DB::table('payment_events')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['paid_at' => DB::raw('updated_at')]);
            });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->dropColumn('paid_at');
        });
    }
};
