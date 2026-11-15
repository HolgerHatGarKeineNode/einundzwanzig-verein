<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `meeting_at`: when the assembly meets in the video call. Every round of
     * the election is held live in that meeting; the date is what the board
     * plans towards and what members are told.
     *
     * `call_url`: the link to that video call, pasted by the board and shown
     * to members on the meeting day.
     */
    public function up(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->dateTime('meeting_at')->nullable()->after('name');
            $table->string('call_url', 2048)->nullable()->after('meeting_at');
        });
    }

    public function down(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->dropColumn(['meeting_at', 'call_url']);
        });
    }
};
