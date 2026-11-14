<?php

namespace Database\Factories;

use App\Models\EinundzwanzigPleb;
use App\Models\ElectionMark;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ElectionMark>
 */
class ElectionMarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_round_id' => ElectionRound::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->active(),
        ];
    }
}
