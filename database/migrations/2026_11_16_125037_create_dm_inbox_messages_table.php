<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The board's inbox: what members write to the notification key (NIP-17
     * replies, direction `in`) and what the board answers (`out`).
     *
     * Only members are stored: a gift wrap from a pubkey that is no member is
     * dropped by `dm:fetch-inbox`, so the key's open inbox cannot fill this
     * table with spam. `rumor_id` makes a reply that several relays return
     * one row. Deleted by the erasure (MembershipService::erasePersonalData).
     */
    public function up(): void
    {
        Schema::create('dm_inbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->char('rumor_id', 64)->nullable()->unique();
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('author_pleb_id')->nullable()->constrained('einundzwanzig_plebs')->nullOnDelete();
            $table->string('status', 16)->nullable();
            $table->timestamps();

            $table->index(['einundzwanzig_pleb_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_inbox_messages');
    }
};
