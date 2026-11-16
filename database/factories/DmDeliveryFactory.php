<?php

namespace Database\Factories;

use App\Enums\DmDeliveryStatus;
use App\Models\DmDelivery;
use App\Models\DmRun;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DmDelivery>
 */
class DmDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dm_run_id' => DmRun::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory(),
            'status' => DmDeliveryStatus::Pending,
        ];
    }
}
