<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: automated-testing, R22 Event model
class EventModelTest extends TestCase
{
    use RefreshDatabase;

    // Feature: automated-testing, R22 Event model — R22.1 relationships
    #[Test]
    public function it_belongs_to_a_user_and_has_many_photos(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Photo::factory()->for($event)->count(2)->create();

        // belongsTo user
        $this->assertInstanceOf(User::class, $event->user);
        $this->assertSame($owner->id, $event->user->id);

        // hasMany photos
        $photos = $event->photos;
        $this->assertInstanceOf(Collection::class, $photos);
        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            $this->assertInstanceOf(Photo::class, $photo);
            $this->assertSame($event->id, $photo->event_id);
        }
    }

    // Feature: automated-testing, R22 Event model — R22.2 uuid auto-generation
    #[Test]
    public function it_auto_generates_a_uuid_when_none_is_provided(): void
    {
        $owner = User::factory()->create();

        // Create directly on the model (no uuid) to genuinely exercise the
        // booted() creating hook — the factory always sets a uuid in definition().
        $event = new Event([
            'name'   => 'Auto UUID Event',
            'slug'   => 'auto-uuid-event',
            'status' => 'active',
        ]);
        $event->user_id = $owner->id;
        $event->save();

        $this->assertNotEmpty($event->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $event->uuid
        );
    }

    // Feature: automated-testing, R22 Event model — R22.3 soft deletes
    #[Test]
    public function it_soft_deletes_events(): void
    {
        $event = Event::factory()->create();
        $id = $event->id;

        $event->delete();

        // Excluded from the default query.
        $this->assertNull(Event::find($id));

        // Still present via withTrashed().
        $trashed = Event::withTrashed()->find($id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);

        $this->assertSoftDeleted('events', ['id' => $id]);
    }

    // Feature: automated-testing, R22 Event model — casts
    #[Test]
    public function it_casts_event_date_and_upload_enabled(): void
    {
        $event = Event::factory()->create([
            'event_date'     => '2025-06-15',
            'upload_enabled' => 1,
        ]);

        $fresh = $event->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $fresh->event_date);
        $this->assertSame('2025-06-15', $fresh->event_date->format('Y-m-d'));

        $this->assertIsBool($fresh->upload_enabled);
        $this->assertTrue($fresh->upload_enabled);
    }

    // Feature: automated-testing, R22 Event model — R22.6 referential integrity
    #[Test]
    public function its_user_id_references_the_correct_user(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->assertSame($owner->id, $event->user_id);
        $this->assertSame($owner->id, $event->fresh()->user->id);
    }
}
