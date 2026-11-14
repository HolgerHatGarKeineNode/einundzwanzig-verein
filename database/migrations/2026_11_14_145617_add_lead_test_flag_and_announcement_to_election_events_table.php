<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - `election_lead_pleb_id`: the Wahlleitung, named per election by board
     *   resolution. May be a member outside the board.
     * - `is_test`: a trial election — visible to the board and its lead only,
     *   and the only kind `election:purge-test` deletes. Fixed at creation.
     * - `announced_at`: from this moment on, membership categories change
     *   only through the lead (see `ChangeMemberStatus`).
     */
    public function up(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->foreignId('election_lead_pleb_id')->nullable()->after('honorary_without_fee')->constrained('einundzwanzig_plebs')->restrictOnDelete();
            $table->boolean('is_test')->default(false)->after('election_lead_pleb_id');
            $table->timestamp('announced_at')->nullable()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('election_lead_pleb_id');
            $table->dropColumn(['is_test', 'announced_at']);
        });
    }
};
