<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Photo>
     */
    protected $model = Photo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'event_id'          => Event::factory(),
            'uuid'              => $uuid,
            'original_filename' => $this->faker->word().'.jpg',
            'original_path'     => 'events/'.Str::uuid().'/originals/'.$uuid.'.jpg',
            'mime_type'         => 'image/jpeg',
            'file_size'         => $this->faker->numberBetween(1000, 5_000_000),
            'width'             => 800,
            'height'            => 600,
            'status'            => Photo::STATUS_READY,
        ];
    }
}
