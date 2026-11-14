<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - `is_test`: a trial election — visible to the board only,
     *   and the only kind `election:purge-test` deletes. Fixed at creation.
     * - `announced_at`: when the election was announced to the members.
     */
    public function up(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('honorary_without_fee');
            $table->timestamp('announced_at')->nullable()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('election_events', function (Blueprint $table): void {
            $table->dropColumn(['is_test', 'announced_at']);
        });
    }
};
