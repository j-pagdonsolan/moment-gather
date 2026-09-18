<?php

// Feature: automated-testing, R23 SlugGenerator service.

namespace Tests\Unit;

use App\Models\Event;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlugGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG_FORMAT = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private function generator(): SlugGenerator
    {
        return app(SlugGenerator::class);
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function it_slugifies_a_simple_name(): void
    {
        $this->assertSame('some-name', $this->generator()->generate('Some Name'));
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function it_collapses_punctuation_and_whitespace_and_trims_hyphens(): void
    {
        $generator = $this->generator();

        $this->assertSame('hello-world', $generator->generate('  Hello,   World!!  '));
        $this->assertSame('a-b', $generator->generate('A & B'));
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function it_returns_empty_string_when_name_has_no_alphanumeric_characters(): void
    {
        $generator = $this->generator();

        // The empty-string signal is what the caller uses to trigger the
        // generateFromUuid() fallback.
        $this->assertSame('', $generator->generate('!!!'));
        $this->assertSame('', $generator->generate('   '));
        $this->assertSame('', $generator->generate('日本'));
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function generate_from_uuid_uses_first_twelve_hex_chars_with_hyphens_stripped(): void
    {
        $generator = $this->generator();

        $slug = $generator->generateFromUuid('0a1b2c3d-4e5f-6789-abcd-ef0123456789');

        $this->assertSame('0a1b2c3d4e5f', $slug);
        $this->assertMatchesRegularExpression(self::SLUG_FORMAT, $slug);
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function exclude_id_ignores_the_events_own_slug_when_regenerating(): void
    {
        $user = User::factory()->create();

        $event = Event::factory()->for($user)->create([
            'name' => 'Gala',
            'slug' => 'gala',
        ]);

        $generator = $this->generator();

        // Without excludeId the existing 'gala' collides and increments.
        $this->assertSame('gala-2', $generator->generate('Gala'));

        // With excludeId set to the event's own id, the collision with itself
        // is ignored and the base slug is returned unchanged.
        $this->assertSame('gala', $generator->generate('Gala', excludeId: $event->id));
    }

    // Feature: automated-testing, R23 SlugGenerator service.
    #[Test]
    public function it_increments_the_suffix_past_existing_slugs(): void
    {
        $user = User::factory()->create();

        Event::factory()->for($user)->create(['name' => 'Expo', 'slug' => 'expo']);
        Event::factory()->for($user)->create(['name' => 'Expo', 'slug' => 'expo-2']);

        $this->assertSame('expo-3', $this->generator()->generate('Expo'));
    }
}
