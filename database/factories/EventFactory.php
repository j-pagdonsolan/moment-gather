<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Event>
     */
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->sentence(3);
        $uuid = (string) Str::uuid();

        $generator = app(SlugGenerator::class);
        $base = $generator->generate($name);
        $slug = $base !== '' ? $base : $generator->generateFromUuid($uuid);

        return [
            'user_id'        => User::factory(),
            'uuid'           => $uuid,
            'name'           => $name,
            'slug'           => $slug,
            'description'    => $this->faker->optional()->paragraph(),
            'event_date'     => $this->faker->optional()->dateTimeBetween('now', '+2 years')?->format('Y-m-d'),
            'location'       => $this->faker->optional()->city(),
            'status'         => $this->faker->randomElement(['active', 'archived']),
            'upload_enabled' => $this->faker->boolean(),
        ];
    }
}
