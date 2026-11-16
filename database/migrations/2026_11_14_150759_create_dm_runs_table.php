<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One sending of a message, with its recipient list frozen when the run
     * was created.
     *
     * `recipient_ids` holds member ids, never pubkeys or npubs, so the list
     * names nobody once a member has been erased. The fan-out
     * (DispatchBulkDirectMessages) turns it into `dm_deliveries` rows and
     * stamps `fanned_out_at`; a second fan-out of the same run adds nothing.
     */
    public function up(): void
    {
        Schema::create('dm_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dm_message_id')->constrained()->cascadeOnDelete();
            $table->json('recipient_ids');
            $table->unsignedInteger('recipient_count');
            $table->timestamp('fanned_out_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_runs');
    }
};
