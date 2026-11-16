<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_proposal_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_proposal_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('einundzwanzig_pleb_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->text('body');
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_proposal_comments');
    }
};
