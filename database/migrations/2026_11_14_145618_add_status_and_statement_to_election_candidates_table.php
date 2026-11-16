<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A candidacy is submitted by the member and then approved, rejected or
     * withdrawn. Only approved candidates are on the ballot.
     *
     * The column default is `pending`: a row that did not say otherwise is
     * not on the ballot.
     */
    public function up(): void
    {
        Schema::table('election_candidates', function (Blueprint $table): void {
            $table->string('status')->default('pending')->after('einundzwanzig_pleb_id');
            $table->text('statement')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('election_candidates', function (Blueprint $table): void {
            $table->dropColumn(['status', 'statement']);
        });
    }
};
