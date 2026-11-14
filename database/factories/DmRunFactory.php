<?php

namespace Database\Factories;

use App\Models\DmMessage;
use App\Models\DmRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmRun>
 */
class DmRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dm_message_id' => DmMessage::factory(),
            'recipient_ids' => [],
            'recipient_count' => 0,
            'fanned_out_at' => null,
        ];
    }
}
