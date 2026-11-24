<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every page counts the member's unread direct messages, a lookup of
 * `dm_deliveries` by member, newest first. Postgres indexes no foreign key on
 * its own, so that count scanned the table.
 *
 * On Postgres the index is built CONCURRENTLY, which writes on while it
 * builds and cannot run inside a transaction. A concurrent build that fails
 * leaves an INVALID index of this name behind: drop it before running this
 * again. SQLite builds a plain index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('dm_deliveries', function (Blueprint $table): void {
            $table->index(['einundzwanzig_pleb_id', 'id'])->online();
        });
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->getDriverName() === 'pgsql') {
            DB::connection($this->getConnection())->statement('drop index concurrently if exists dm_deliveries_einundzwanzig_pleb_id_id_index');

            return;
        }

        Schema::table('dm_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['einundzwanzig_pleb_id', 'id']);
        });
    }
};
