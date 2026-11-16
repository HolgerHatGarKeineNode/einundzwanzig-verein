<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An election of the association: the container for its rounds and the
     * place where the fee rule of the electoral roll is named.
     *
     * NOT called `elections`. `2026_08_06_224931_drop_elections_table` recreates
     * a table of that name in its `down()`, and a rollback across both would
     * collide.
     */
    public function up(): void
    {
        Schema::create('election_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('fee_rule');
            $table->unsignedInteger('fee_year')->nullable();
            $table->boolean('honorary_without_fee')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_events');
    }
};
