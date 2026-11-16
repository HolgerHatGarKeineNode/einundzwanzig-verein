<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One vote per member and proposal.
     *
     * The unique index is the rule, not a convenience: the page offers "vote
     * again to change, same value again to withdraw", and two concurrent
     * requests of one member must not be able to leave two rows that both
     * count. The row names the member because nobody could change a vote
     * otherwise; no page ever shows that link.
     */
    public function up(): void
    {
        Schema::create('member_proposal_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_proposal_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('value', 4);
            $table->timestamps();

            $table->unique(['member_proposal_id', 'einundzwanzig_pleb_id'], 'member_proposal_votes_one_per_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_proposal_votes');
    }
};
