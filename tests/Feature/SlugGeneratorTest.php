<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlugGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG_FORMAT = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    // Property: Feature moment-gather-foundation, Property 1: Slug format invariant
    // Validates: Requirements 5.1, 5.5, 17.4
    #[Test]
    public function generated_slugs_always_match_the_expected_format(): void
    {
        $generator = app(SlugGenerator::class);

        for ($i = 0; $i < 100; $i++) {
            $name = $this->randomNameWithAlphanumeric();

            $slug = $generator->generate($name);

            if ($slug === '') {
                $slug = $generator->generateFromUuid((string) Str::uuid());
            }

            $this->assertMatchesRegularExpression(
                self::SLUG_FORMAT,
                $slug,
                "Slug '{$slug}' derived from name '{$name}' does not match the expected format."
            );
        }
    }

    // Property: Feature moment-gather-foundation, Property 2: Slug uniqueness
    // Validates: Requirements 5.2, 17.2
    #[Test]
    public function factory_generated_slugs_are_unique_and_well_formed(): void
    {
        $user = User::factory()->create();

        $events = Event::factory()->count(10)->for($user)->create();

        $slugs = $events->pluck('slug');

        $this->assertSame(10, $slugs->unique()->count(), 'Expected all 10 generated slugs to be unique.');

        foreach ($slugs as $slug) {
            $this->assertMatchesRegularExpression(
                self::SLUG_FORMAT,
                $slug,
                "Slug '{$slug}' does not match the expected format."
            );
        }
    }

    #[Test]
    public function repeated_names_receive_incrementing_suffixes(): void
    {
        $user = User::factory()->create();
        $generator = app(SlugGenerator::class);
        $name = 'My Birthday Party';

        $expected = ['my-birthday-party', 'my-birthday-party-2', 'my-birthday-party-3'];

        foreach ($expected as $expectedSlug) {
            $slug = $generator->generate($name);

            $this->assertSame($expectedSlug, $slug);

            Event::factory()->for($user)->create([
                'name' => $name,
                'slug' => $slug,
            ]);
        }
    }

    /**
     * Build a random name that is guaranteed to contain at least one
     * alphanumeric character. Mixes letters, digits, spaces, punctuation
     * and the occasional unicode character to exercise the slugifier.
     */
    private function randomNameWithAlphanumeric(): string
    {
        $alphanumeric = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $noise = [' ', '  ', '-', '_', '.', ',', '!', '?', '/', '&', '@', '#', '(', ')', 'é', 'ü', 'ñ', 'ß', '日', '本'];

        // Guarantee at least one alphanumeric character up front.
        $name = $alphanumeric[random_int(0, strlen($alphanumeric) - 1)];

        $segments = random_int(1, 6);
        for ($s = 0; $s < $segments; $s++) {
            // Append a chunk of alphanumeric characters.
            $chunkLength = random_int(1, 8);
            for ($c = 0; $c < $chunkLength; $c++) {
                $name .= $alphanumeric[random_int(0, strlen($alphanumeric) - 1)];
            }

            // Append some noise (spaces, punctuation, unicode).
            $name .= $noise[random_int(0, count($noise) - 1)];
        }

        return $name;
    }
}
