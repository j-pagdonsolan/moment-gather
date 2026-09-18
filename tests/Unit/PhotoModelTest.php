<?php

// Feature: automated-testing, R22 Photo model

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotoModelTest extends TestCase
{
    use RefreshDatabase;

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function photo_belongs_to_event(): void
    {
        $event = Event::factory()->create();
        $photo = Photo::factory()->for($event)->create();

        $this->assertInstanceOf(Event::class, $photo->event);
        $this->assertSame($event->id, $photo->event->id);
        $this->assertSame($event->id, $photo->event_id);
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function uuid_is_auto_generated_when_created_without_one(): void
    {
        $event = Event::factory()->create();

        // Create the Photo directly (not via factory, which sets its own uuid)
        // so the model's creating() hook is exercised.
        $photo = new Photo([
            'event_id'          => $event->id,
            'original_filename' => 'sample.jpg',
            'original_path'     => 'events/'.$event->uuid.'/originals/sample.jpg',
            'mime_type'         => 'image/jpeg',
            'file_size'         => 12345,
            'width'             => 800,
            'height'            => 600,
            'status'            => Photo::STATUS_PENDING,
        ]);
        $photo->save();

        $photo->refresh();

        $this->assertNotEmpty($photo->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $photo->uuid
        );
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function photo_uses_soft_deletes(): void
    {
        $photo = Photo::factory()->create();
        $id = $photo->id;

        $photo->delete();

        // Excluded from the default query.
        $this->assertNull(Photo::find($id));

        // Present via withTrashed().
        $trashed = Photo::withTrashed()->find($id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);

        $this->assertSoftDeleted('photos', ['id' => $id]);
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function status_constants_match_their_literal_values(): void
    {
        $this->assertSame('pending', Photo::STATUS_PENDING);
        $this->assertSame('processing', Photo::STATUS_PROCESSING);
        $this->assertSame('ready', Photo::STATUS_READY);
        $this->assertSame('failed', Photo::STATUS_FAILED);
        $this->assertSame('deleted', Photo::STATUS_DELETED);
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function url_accessors_fall_back_to_original_path_when_variants_are_null(): void
    {
        Storage::fake('public');

        $photo = Photo::factory()->create([
            'original_path'  => 'events/e1/originals/orig.jpg',
            'optimized_path' => null,
            'thumbnail_path' => null,
        ]);

        $originalUrl = $photo->originalUrl();

        $this->assertSame($originalUrl, $photo->optimizedUrl());
        $this->assertSame($originalUrl, $photo->thumbnailUrl());
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function url_accessors_use_their_own_path_when_set(): void
    {
        Storage::fake('public');

        $photo = Photo::factory()->create([
            'original_path'  => 'events/e1/originals/orig.jpg',
            'optimized_path' => 'events/e1/optimized/opt.webp',
            'thumbnail_path' => 'events/e1/thumbnails/thumb.webp',
        ]);

        $this->assertStringContainsString('events/e1/originals/orig.jpg', $photo->originalUrl());

        $optimizedUrl = $photo->optimizedUrl();
        $this->assertStringContainsString('events/e1/optimized/opt.webp', $optimizedUrl);
        $this->assertStringNotContainsString('originals/orig.jpg', $optimizedUrl);

        $thumbnailUrl = $photo->thumbnailUrl();
        $this->assertStringContainsString('events/e1/thumbnails/thumb.webp', $thumbnailUrl);
        $this->assertStringNotContainsString('originals/orig.jpg', $thumbnailUrl);
    }

    // Feature: automated-testing, R22 Photo model
    #[Test]
    public function photo_event_id_references_the_correct_event(): void
    {
        $event = Event::factory()->create();
        $photo = Photo::factory()->for($event)->create();

        // A photo is always created against an event (no orphan by design).
        $this->assertNotNull($photo->event);
        $this->assertSame($event->id, $photo->event->id);
        $this->assertSame($event->uuid, $photo->event->uuid);
    }
}
