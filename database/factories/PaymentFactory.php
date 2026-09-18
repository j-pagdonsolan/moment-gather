<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Payment>
     */
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'             => User::factory(),
            'subscription_id'     => null,
            'provider'            => 'fake',
            'provider_payment_id' => 'fake_pay_'.fake()->unique()->uuid(),
            'amount'              => 49900,
            'currency'            => 'PHP',
            'status'              => Payment::STATUS_SUCCEEDED,
            'paid_at'             => now(),
            'metadata'            => null,
        ];
    }

    /**
     * Succeeded payment: settled with a paid_at timestamp.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'  => Payment::STATUS_SUCCEEDED,
            'paid_at' => now(),
        ]);
    }

    /**
     * Failed payment: never settled, no paid_at timestamp.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'  => Payment::STATUS_FAILED,
            'paid_at' => null,
        ]);
    }
}
