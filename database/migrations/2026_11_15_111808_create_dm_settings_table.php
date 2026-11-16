<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * At most one row: the switches of the direct message sending that the
     * board flips at runtime. Today only the pause (`paused_at` set = no new
     * run starts and no further delivery is queued or sent); see
     * App\Services\Dm\DirectMessagePause.
     */
    public function up(): void
    {
        Schema::create('dm_settings', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_settings');
    }
};
