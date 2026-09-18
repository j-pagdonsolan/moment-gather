<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: security-rate-limiting — SecurityHeaders middleware (R19).
// The middleware is appended to the 'web' group (bootstrap/app.php) and sets
// X-Content-Type-Options: nosniff and X-Frame-Options: SAMEORIGIN on every web
// response. No CSP by design. It runs on the response regardless of status, so
// even a 404 web response carries the headers.
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // R19.2, R19.3: a 404 web response (nonexistent event slug) carries both
    // security headers. This route does not render a heavy Blade/Vite page, so
    // it is robust and has no Vite-manifest dependency.
    #[Test]
    public function headers_present_on_404_web_response(): void
    {
        $response = $this->get('/e/no-such-event-'.uniqid());

        $response->assertNotFound();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    // R19.2, R19.3, R19.4: an active public event page requested as an Inertia
    // XHR returns a JSON/Inertia response (no full Blade render, so no stale
    // Vite manifest 500) and still carries both headers. Confirms Inertia is
    // not broken by the middleware.
    #[Test]
    public function headers_present_on_inertia_event_page_response(): void
    {
        Event::factory()->create([
            'status' => 'active',
            'slug'   => 'headers-inertia-check',
        ]);

        $version = (new HandleInertiaRequests())->version(request());

        $response = $this->get('/e/headers-inertia-check', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => $version,
        ]);

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    // R19.4 sanity: the middleware itself does not break responses. The status
    // is a normal web status (2xx/3xx/404), never a 500 originating from the
    // middleware.
    #[Test]
    public function middleware_does_not_break_the_response(): void
    {
        $response = $this->get('/e/no-such-event-'.uniqid());

        $this->assertContains($response->getStatusCode(), [200, 301, 302, 404]);
        $this->assertNotSame(500, $response->getStatusCode());
    }
}
