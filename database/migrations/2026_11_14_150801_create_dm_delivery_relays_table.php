<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one inbox relay answered to one delivery's wrap, as the latest
     * attempt left it.
     *
     * One row per (delivery, relay). A retry publishes only to relays whose
     * row is not `accepted`; `status` takes the values of
     * App\Services\Nostr\RelayPublishStatus.
     *
     * The relay URLs say where a member reads, so the erasure deletes these
     * rows along with the member's `pleb_dm_relays` row.
     */
    public function up(): void
    {
        Schema::create('dm_delivery_relays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dm_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('relay_url', 512);
            $table->string('status');
            $table->string('message', 500)->default('');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['dm_delivery_id', 'relay_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_delivery_relays');
    }
};
