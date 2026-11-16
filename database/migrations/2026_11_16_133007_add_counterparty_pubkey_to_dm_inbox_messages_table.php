<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The board inbox takes direct messages from anyone, not only from portal
 * members: a conversation is keyed by the other side's pubkey, and the member
 * link becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_inbox_messages', function (Blueprint $table): void {
            $table->char('counterparty_pubkey', 64)->nullable()->after('einundzwanzig_pleb_id');
            $table->index(['counterparty_pubkey', 'id']);
        });

        Schema::table('dm_inbox_messages', function (Blueprint $table): void {
            $table->foreignId('einundzwanzig_pleb_id')->nullable()->change();
        });

        DB::table('dm_inbox_messages')
            ->whereNull('counterparty_pubkey')
            ->update(['counterparty_pubkey' => DB::raw('(select pubkey from einundzwanzig_plebs where einundzwanzig_plebs.id = dm_inbox_messages.einundzwanzig_pleb_id)')]);
    }

    public function down(): void
    {
        DB::table('dm_inbox_messages')->whereNull('einundzwanzig_pleb_id')->delete();

        Schema::table('dm_inbox_messages', function (Blueprint $table): void {
            $table->dropIndex(['counterparty_pubkey', 'id']);
            $table->dropColumn('counterparty_pubkey');
        });

        Schema::table('dm_inbox_messages', function (Blueprint $table): void {
            $table->foreignId('einundzwanzig_pleb_id')->nullable(false)->change();
        });
    }
};
