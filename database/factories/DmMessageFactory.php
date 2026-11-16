<?php

namespace Database\Factories;

use App\Enums\DmMessageType;
use App\Models\DmMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmMessage>
 */
class DmMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DmMessageType::Individual,
            'plaintext' => 'Hallo {name}, die Mitgliederversammlung beginnt um 19 Uhr.',
            'variables' => [],
            'author_pleb_id' => null,
        ];
    }
}
