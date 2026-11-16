<?php

namespace Database\Factories;

use App\Enums\DmAudience;
use App\Models\DmMessage;
use App\Models\DmSchedule;
use App\Models\Election;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmSchedule>
 */
class DmScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_event_id' => Election::factory(),
            'dm_message_id' => DmMessage::factory(),
            'audience' => DmAudience::Members,
            'send_at' => now()->addDay(),
        ];
    }
}
