<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A direct message as the board wrote it: its type, its text with
     * placeholders and the values of the message-wide placeholders.
     *
     * The message is a database row; Nostr is one way of delivering it. What
     * each recipient actually received (placeholders filled in) lives on
     * `dm_deliveries`, one row per recipient and run.
     *
     * `author_pleb_id` is null for messages the application sends on its own
     * (schedule, fee reminder).
     */
    public function up(): void
    {
        Schema::create('dm_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('plaintext');
            $table->json('variables')->nullable();
            $table->foreignId('author_pleb_id')->nullable()->constrained('einundzwanzig_plebs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_messages');
    }
};
