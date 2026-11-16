<?php

namespace Database\Factories;

use App\Enums\ElectionFeeRule;
use App\Models\Election;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Election>
 */
class ElectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Vorstandswahl '.fake()->year(),
            'fee_rule' => ElectionFeeRule::Year,
            'fee_year' => (int) now()->year,
            'honorary_without_fee' => false,
        ];
    }

    public function feeRule(ElectionFeeRule $rule, ?int $year = null): static
    {
        return $this->state(fn () => [
            'fee_rule' => $rule,
            'fee_year' => $year,
        ]);
    }

    public function test(): static
    {
        return $this->state(fn () => ['is_test' => true]);
    }

    public function announced(): static
    {
        return $this->state(fn () => ['announced_at' => now()]);
    }

    public function honoraryWithoutFee(): static
    {
        return $this->state(fn () => ['honorary_without_fee' => true]);
    }
}
