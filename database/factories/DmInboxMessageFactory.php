<?php

namespace Database\Factories;

use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmInboxMessage>
 */
class DmInboxMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->active(),
            'direction' => DmInboxMessage::IN,
            'rumor_id' => bin2hex(random_bytes(32)),
            'body' => fake()->sentence(),
            'sent_at' => now()->subMinutes(5),
        ];
    }

    public function answer(EinundzwanzigPleb $author): static
    {
        return $this->state(fn (): array => [
            'direction' => DmInboxMessage::OUT,
            'author_pleb_id' => $author->id,
            'status' => DmInboxMessage::STATUS_SENT,
            'read_at' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }
}
