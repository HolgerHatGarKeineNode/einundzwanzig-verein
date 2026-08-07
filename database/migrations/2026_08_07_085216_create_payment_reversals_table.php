<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per booked fee that was taken back again.
     *
     * `payment_events.paid` is a flag, and a flag has no memory: flipping it
     * back to false would leave the record indistinguishable from a fee that
     * was never paid at all. That is the wrong history. Somebody DID pay, the
     * association DID receive the invoice as settled, and BTCPay later
     * declared it invalid — all three of those are facts, and the last one
     * must not erase the first two.
     *
     * The amount and year are copied rather than joined. They are what was
     * reversed at the time; the payment event lives on and may be invoiced
     * again for the same fee year, at which point a join would report today's
     * numbers for yesterday's reversal.
     *
     * Deliberately NOT here: a status change. Art. 4.2 of the statutes rules
     * out any claim to a refund, and a payment provider does not get to decide
     * who is a member — the category stays, the booking is what moves.
     */
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table): void {
            $table->id();
            /*
             * NOT `cascadeOnDelete`, and that was a measured defect rather
             * than a preference. With the cascade in place, releasing an
             * "expired" invoice deleted the fee row and took the Storno with
             * it — so the record of the reversal was destroyed by the very
             * situation it exists to document, and a promoted member was left
             * with no trace of a payment, a reversal or a reason.
             *
             * `restrictOnDelete` makes that impossible at the schema level:
             * a fee row carrying a reversal cannot be deleted at all. The
             * application-side guard in `releaseExpiredInvoice()` is the one
             * that gives a good error message; this is the one that cannot be
             * forgotten by the next caller. A record that a delete can drag
             * away with it is not a record.
             */
            $table->foreignId('payment_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('einundzwanzig_pleb_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('year');
            $table->unsignedBigInteger('amount');
            $table->string('currency');
            $table->string('reason');
            $table->string('source');
            $table->string('delivery_id')->nullable();
            $table->timestamp('reversed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
