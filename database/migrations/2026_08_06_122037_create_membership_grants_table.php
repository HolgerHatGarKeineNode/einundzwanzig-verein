<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per membership granted by a paid annual fee.
     *
     * A log line would not answer "which payment made this person a member?" —
     * logs rotate, are not joinable and cannot be shown next to the record.
     * Since the payment is what constitutes the membership, that link is a
     * bookkeeping fact and belongs in the database.
     *
     * `payment_event_id` is unique: the same payment can never grant twice, so
     * the idempotency of grantMembershipOnPayment() has a second line of
     * defence in the schema rather than only in application code.
     */
    public function up(): void
    {
        Schema::create('membership_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_status');
            $table->unsignedInteger('to_status');
            $table->unsignedInteger('year');
            $table->timestamp('granted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_grants');
    }
};
