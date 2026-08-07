<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The de-duplication ledger for BTCPay webhook deliveries.
     *
     * Until now the webhook was idempotent by accident: `where('paid', false)
     * ->update(['paid' => true])` is simply a no-op the second time round.
     * From this phase on the same delivery also raises a membership category
     * and writes a grant, so "no-op by luck" is no longer good enough — and
     * BTCPay redelivers on its own.
     *
     * WHICH FIELD IS THE KEY, and why it is NOT `deliveryId`. Read off the
     * BTCPay Server source (github.com/btcpayserver/btcpayserver, master,
     * fetched 2026-08-07):
     *
     *   BTCPayServer/Plugins/Webhooks/HostedServices/WebhookProviderHostedService.cs:48-52
     *       var delivery = Data.WebhookDeliveryData.Create(webhook.Id);
     *       ev.DeliveryId = delivery.Id;
     *       ev.OriginalDeliveryId = delivery.Id;
     *       ev.Timestamp = delivery.Timestamp;
     *       ev.IsRedelivery = false;
     *
     *   BTCPayServer/Plugins/Webhooks/WebhookSender.cs:90-100
     *       var newDelivery = WebhookDeliveryData.Create(webhookDelivery.Webhook.Id);
     *       webhookEvent.DeliveryId = newDelivery.Id;          // a NEW id
     *       // if we redelivered a redelivery, we still want the initial delivery here
     *       webhookEvent.OriginalDeliveryId ??= deliveryId;    // stays put
     *       webhookEvent.IsRedelivery = true;
     *
     * So `deliveryId` is fresh on every attempt while `originalDeliveryId` is
     * present from the very first delivery and stable across all retries of
     * the same event. Keying on `deliveryId` would have looked correct and
     * would have let every single automatic retry through — and BTCPay retries
     * on 5xx, 429, 408 and connection errors, eight times, over roughly an
     * hour (WebhookSender.cs:121-140). `originalDeliveryId` is the key.
     *
     * `processed_at` is set only AFTER the work succeeded. A delivery that
     * blew up halfway (BTCPay unreachable while verifying the amount) leaves
     * the row claimed but unprocessed, our answer is a 5xx, and BTCPay's next
     * attempt picks the work up again instead of finding a "done" marker over
     * a job that never ran.
     */
    public function up(): void
    {
        Schema::create('btc_pay_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_key')->unique();
            $table->string('delivery_id')->nullable();
            $table->string('type');
            $table->string('invoice_id')->nullable()->index();
            $table->boolean('is_redelivery')->default(false);
            /*
             * Nullable rather than defaulted: BTCPay only sends these on the
             * event types that have them, and "the store did not say" is a
             * different fact from "the store said no". `manuallyMarked` is the
             * one that matters — a settlement clicked by hand in the BTCPay
             * backend raises a membership just like a real payment does, and
             * the amount check cannot narrow that case down by even one
             * invoice, because a manual settle reports the invoiced amount by
             * construction. Unrecorded, it is unknowable afterwards.
             */
            $table->boolean('manually_marked')->nullable();
            $table->boolean('over_paid')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btc_pay_webhook_deliveries');
    }
};
