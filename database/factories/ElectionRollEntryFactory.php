<?php

namespace Database\Factories;

use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ElectionRollEntry>
 */
class ElectionRollEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_round_id' => ElectionRound::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->active(),
            'reason' => ElectionRollReason::ActiveFeePaid,
            'source' => ElectionRollSource::Opening,
        ];
    }
}
