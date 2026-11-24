<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The amount the board approved, frozen at the deciding vote. Caps the payout
 * so that a later edit of `support_in_sats` cannot raise what is paid out.
 *
 * Nullable on purpose: proposals decided before this column existed keep
 * null, and ProjectProposal::approvedSats() falls back to the requested amount
 * for them — the behaviour they had until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_sats')->nullable()->after('support_in_sats');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table): void {
            $table->dropColumn('approved_sats');
        });
    }
};
