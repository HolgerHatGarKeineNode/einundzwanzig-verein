<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per incoming payment that was REFUSED and needs a human.
     *
     * Since the paid fee is what constitutes the membership, a payment that
     * does not match what was invoiced can no longer be booked and cannot be
     * silently dropped either: somebody sent money, and the association has to
     * be able to find that out. A log line would not do — the same reasoning
     * as for `membership_grants`: this is a bookkeeping fact, it has to be
     * joinable, and logs rotate.
     *
     * Nullable on both foreign keys on purpose. The `unknown_invoice` case is
     * precisely the one where no member record can be reached: BTCPay reports
     * an invoice this database has never heard of, which is the orphan the
     * reconciliation command exists to chase. A review row that could only be
     * written when the link already existed would be blind to exactly the
     * situation it is for.
     */
    public function up(): void
    {
        Schema::create('payment_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->string('source');
            $table->string('btc_pay_invoice')->nullable()->index();
            $table->string('delivery_id')->nullable();
            $table->unsignedBigInteger('expected_amount')->nullable();
            $table->string('expected_currency')->nullable();
            $table->string('observed_amount')->nullable();
            $table->string('observed_currency')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['reason', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reviews');
    }
};
