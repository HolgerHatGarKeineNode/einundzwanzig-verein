<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member's DM inbox relays, read from their kind 10050 list (NIP-17).
     *
     * NIP-17: "Clients MUST only publish events to the relays listed in the
     * recipient's kind 10050 event", and no list means "the user is not ready
     * to receive messages". So a member without a row here gets no direct
     * message — there is no fallback relay on purpose.
     *
     * `event_id` and `event_created_at` identify the list the relays came
     * from: a newer list replaces the row, an older one never does.
     *
     * Written by `nostr:sync-dm-relays`; deleted by the erasure
     * (MembershipService::erasePersonalData), which anonymises the member row
     * instead of deleting it, so the cascade alone would never fire there.
     */
    public function up(): void
    {
        Schema::create('pleb_dm_relays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('einundzwanzig_pleb_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('relays');
            $table->char('event_id', 64);
            $table->unsignedBigInteger('event_created_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pleb_dm_relays');
    }
};
