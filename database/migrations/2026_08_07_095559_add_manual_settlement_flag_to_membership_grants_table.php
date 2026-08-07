<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was the payment that created this membership settled by a person
     * clicking "mark settled" in the BTCPay backend?
     *
     * It belongs on the grant and not only in the delivery ledger because the
     * grant is the answer to "why is this person a member", and that answer is
     * materially different when the money was never observed on a chain. The
     * amount check offers no protection here at all: a manual settlement
     * reports the invoiced amount by definition, so the comparison succeeds
     * every time. Recorded at the moment it happens or not at all — afterwards
     * nothing distinguishes it from a real payment.
     *
     * Nullable, with no backfill. Grants written before this column existed
     * were made under a webhook that could not have known, and `null` says
     * "not established" rather than falsely claiming "not manual".
     */
    public function up(): void
    {
        Schema::table('membership_grants', function (Blueprint $table): void {
            $table->boolean('manually_marked')->nullable()->after('year');
            $table->boolean('over_paid')->nullable()->after('manually_marked');
        });
    }

    public function down(): void
    {
        Schema::table('membership_grants', function (Blueprint $table): void {
            $table->dropColumn(['manually_marked', 'over_paid']);
        });
    }
};
