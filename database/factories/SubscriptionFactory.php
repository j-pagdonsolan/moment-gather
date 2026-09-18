<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Subscription>
     */
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'                  => User::factory(),
            'plan'                     => 'pro',
            'provider'                 => 'fake',
            'provider_subscription_id' => 'fake_sub_'.fake()->unique()->uuid(),
            'status'                   => Subscription::STATUS_ACTIVE,
            'current_period_start'     => now()->subDays(5),
            'current_period_end'       => now()->addMonth(),
            'cancel_at_period_end'     => false,
            'canceled_at'              => null,
        ];
    }

    /**
     * Active subscription within its paid period.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'               => Subscription::STATUS_ACTIVE,
            'current_period_end'   => now()->addMonth(),
            'cancel_at_period_end' => false,
            'canceled_at'          => null,
        ]);
    }

    /**
     * Canceled but still within the paid grace period (future period end).
     *
     * Satisfies Subscription::isActiveNow() and onGracePeriod().
     */
    public function canceledButActive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'               => Subscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
            'canceled_at'          => now(),
            'current_period_end'   => now()->addWeek(),
        ]);
    }

    /**
     * Alias of canceledButActive(): canceled + still within paid grace period.
     */
    public function onGracePeriod(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'               => Subscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
            'canceled_at'          => now(),
            'current_period_end'   => now()->addWeek(),
        ]);
    }

    /**
     * Expired subscription: period end is in the past.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'               => Subscription::STATUS_EXPIRED,
            'current_period_end'   => now()->subDay(),
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Past-due subscription (failed renewal).
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Subscription::STATUS_PAST_DUE,
        ]);
    }

    /**
     * Incomplete subscription (initial payment not completed).
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Subscription::STATUS_INCOMPLETE,
        ]);
    }
}
