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

    /**
     * Pending state: awaiting processing, no processed variants yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'         => Photo::STATUS_PENDING,
            'optimized_path' => null,
            'thumbnail_path' => null,
        ]);
    }

    /**
     * Processing state: variants are being generated.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Photo::STATUS_PROCESSING,
        ]);
    }

    /**
     * Failed state: processing failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Photo::STATUS_FAILED,
        ]);
    }

    /**
     * Ready state: processing complete with optimized + thumbnail variants.
     *
     * The event uuid is not available inside the state closure, so the
     * processed paths are derived once the related event is resolvable via
     * afterMaking/afterCreating, mirroring PhotoProcessor output exactly:
     *   events/{eventUuid}/optimized/{photoUuid}.webp
     *   events/{eventUuid}/thumbnails/{photoUuid}.webp
     */
    public function ready(): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'status' => Photo::STATUS_READY,
            ])
            ->afterMaking(function (Photo $photo): void {
                $this->applyProcessedPaths($photo);
            })
            ->afterCreating(function (Photo $photo): void {
                $this->applyProcessedPaths($photo);
                $photo->save();
            });
    }

    /**
     * Derive and set the processed variant paths from the related event uuid
     * and the photo uuid, mirroring PhotoProcessor output exactly.
     */
    private function applyProcessedPaths(Photo $photo): void
    {
        $event = $photo->event ?? $photo->loadMissing('event')->event ?? $photo->event()->first();

        $eventUuid = $event?->uuid ?? (string) Str::uuid();
        $photoUuid = $photo->uuid ?: (string) Str::uuid();

        $photo->uuid           = $photoUuid;
        $photo->optimized_path = "events/{$eventUuid}/optimized/{$photoUuid}.webp";
        $photo->thumbnail_path = "events/{$eventUuid}/thumbnails/{$photoUuid}.webp";
    }
}
