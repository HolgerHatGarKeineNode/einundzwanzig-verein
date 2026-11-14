<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposals members put to the association.
     *
     * Separate from `project_proposals` on purpose: those are funding
     * requests decided by a board majority, these are an opinion poll among
     * the members with a state the board sets by hand.
     *
     * `withdrawn_at` instead of a delete: a withdrawn proposal disappears from
     * every page, but the comments other members wrote under it are theirs
     * and are not removed by somebody else's decision.
     */
    public function up(): void
    {
        Schema::create('member_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->text('description');
            $table->string('state', 20)->default('open')->index();
            $table->text('board_note')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_proposals');
    }
};
