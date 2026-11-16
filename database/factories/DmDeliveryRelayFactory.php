<?php

namespace Database\Factories;

use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Services\Nostr\RelayPublishStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmDeliveryRelay>
 */
class DmDeliveryRelayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dm_delivery_id' => DmDelivery::factory(),
            'relay_url' => 'wss://inbox.example.com/',
            'status' => RelayPublishStatus::Accepted,
            'message' => '',
            'attempts' => 1,
        ];
    }
}
