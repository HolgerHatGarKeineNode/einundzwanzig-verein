<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a run was meant for, so the board's history can say it for manual
 * runs too: the named audience (App\Enums\DmAudience) and, when the audience
 * needs one, the election. Both stay null for runs started before these
 * columns existed and for a test sent to a single board member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_runs', function (Blueprint $table): void {
            $table->string('audience')->nullable()->after('dm_message_id');
            $table->foreignId('election_event_id')->nullable()->after('audience')->constrained('election_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dm_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('election_event_id');
            $table->dropColumn('audience');
        });
    }
};
