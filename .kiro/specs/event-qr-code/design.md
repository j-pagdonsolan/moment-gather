# Design Document

## Overview

Phase 4 gives every event a QR code that points to its existing public page at `/e/{slug}`. Organizers view the QR code on their own event's organizer page (`resources/js/pages/Events/Show.tsx`), download it as a high-resolution PNG for printing, copy the public link, and open the public page directly.

The QR code is generated **entirely client-side in React** using the `qrcode.react` package. The backend never renders an image. The environment lacks the `imagick` and `gd` PHP extensions, so server-side PNG generation is not viable; client-side rendering satisfies every functional need because the PNG is extracted from the browser canvas.

The scope is deliberately minimal:

- **Backend**: one added Inertia prop (`publicUrl`) from `EventController@show`. No new route, no new controller, no migration, no database column, no policy change.
- **Frontend**: one new npm dependency (`qrcode.react`), one new reusable component (`resources/js/components/QrCode.tsx`), and one new QR section on `Events/Show.tsx` that replaces the existing "QR Code" Coming Soon card.
- **Reuse**: authorization is delegated entirely to the existing `EventPolicy@view`; the public destination is the unchanged Phase 3 `public.events.show` route.

The public URL is produced server-side with `route('public.events.show', ['slug' => $event->slug])`, so the domain derives from application configuration and is never hardcoded.

## Architecture

```mermaid
flowchart TD
    Browser["Organizer Browser"] -->|GET /events/{uuid}| MW["auth + verified middleware"]
    MW -->|guest| Login["Redirect to /login"]
    MW -->|authenticated| Show["EventController@show"]
    Show -->|authorize('view', event)| Policy["EventPolicy@view\n(user.id === event.user_id)"]
    Policy -->|not owner| Forbidden["HTTP 403"]
    Policy -->|owner| Render["Inertia::render('Events/Show', {\n  event,\n  publicUrl: route('public.events.show', { slug })\n})"]
    Render --> Page["React page Events/Show.tsx"]
    Page --> QR["QrCode component"]
    QR --> Canvas["QRCodeCanvas(value = publicUrl)"]
    QR -->|Download PNG| Download["canvas.toDataURL('image/png')\n→ anchor download\nmomentgather-{slug}-qr.png"]
    QR -->|Copy Link| Clipboard["navigator.clipboard.writeText(publicUrl)\n→ toast"]
    QR -->|Open Event| Public["publicUrl → GET /e/{slug}"]
    Public --> PublicCtrl["PublicEventController@show\n(Phase 3, unchanged)"]
    PublicCtrl -->|active & not deleted| PublicOk["HTTP 200 → Public/Event"]
    PublicCtrl -->|archived / draft / soft-deleted| PublicNotFound["HTTP 404"]
```

Key points:

- The QR code is generated client-side; the backend never renders or stores an image.
- Authorization happens once, in `show()`, via `EventPolicy@view`. Non-owners get 403; guests are redirected by the `auth` middleware.
- The QR destination (`/e/{slug}`) is the existing Phase 3 public route and controller, untouched by this feature.

## Components and Interfaces

### Backend Component — `EventController@show`

The only backend change. The `show` action gains one additional prop.

Before:

```php
public function show(Request $request, Event $event): Response
{
    $this->authorize('view', $event);

    return Inertia::render('Events/Show', ['event' => $event]);
}
```

After:

```php
public function show(Request $request, Event $event): Response
{
    $this->authorize('view', $event);

    return Inertia::render('Events/Show', [
        'event'     => $event,
        'publicUrl' => route('public.events.show', ['slug' => $event->slug]),
    ]);
}
```

Design decisions and rationale:

- **`route()` for the URL, no hardcoding.** `route('public.events.show', ['slug' => $event->slug])` generates an absolute URL from application configuration. The domain is never embedded in code.
- **Authorization unchanged.** `$this->authorize('view', $event)` continues to delegate to `EventPolicy@view` (`$user->id === $event->user_id`). No duplicate authorization logic is added. Non-owner → 403; guest → redirected by `auth` middleware.
- **No new route or controller.** The existing `events.show` route (`GET /events/{event}`, bound by uuid, guarded by `auth` + `verified`) is reused.
- **Status-independent.** `publicUrl` is included for the owner's own event regardless of its `status` (`active`, `archived`, `draft`). The organizer can prepare and print a QR code before the event goes live. The *destination* page still 404s for non-active events (Phase 3 rule), but the URL always resolves as a string and the organizer can always see and download the QR code. This is intentional: the QR code encodes a stable link; only the public page's visibility is gated by status.

### Frontend Component — `resources/js/components/QrCode.tsx`

A reusable component that renders a QR code from a URL and owns the Download, Copy, and Open controls.

Props interface:

```ts
interface QrCodeProps {
    /** The absolute public URL to encode. */
    url: string;
    /** The download filename, e.g. "momentgather-summer-party-qr.png". */
    fileName: string;
    /** Optional CSS class for the visible QR container. */
    className?: string;
}
```

The page passes `url={publicUrl}` and a slug-derived `fileName`. Keeping the filename shape at the call site (the page owns the event's slug) keeps the component free of MomentGather-specific naming and easy to reuse.

**Single high-resolution canvas approach.** Rather than maintaining two canvases (one for display, one for download), the component renders a single `QRCodeCanvas` at high resolution (`size={1024}`, `marginSize={4}`) and constrains its displayed dimensions with CSS (`className` width constraints). One high-res canvas serves both purposes: it stays crisp on screen and is directly downloadable as a print-quality PNG. This satisfies the display, responsiveness, and print requirements with the least moving parts.

QR configuration (satisfies Requirement 5):

| Prop | Value | Requirement |
| --- | --- | --- |
| `value` | `url` | 4.1 |
| `size` | `1024` (>= 1024 px per side) | 5.4 |
| `marginSize` | `4` (>= 4 modules quiet zone) | 5.3 |
| `bgColor` | `#ffffff` (white background) | 5.2 |
| `fgColor` | `#000000` | — |
| `level` | `'M'` (error correction) | — |

Representative TSX skeleton:

```tsx
import { QRCodeCanvas } from 'qrcode.react';
import { useRef } from 'react';
import { Copy, Download, ExternalLink } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

interface QrCodeProps {
    url: string;
    fileName: string;
    className?: string;
}

export default function QrCode({ url, fileName, className }: QrCodeProps) {
    const containerRef = useRef<HTMLDivElement>(null);

    const handleDownload = () => {
        const canvas = containerRef.current?.querySelector('canvas');
        if (!canvas) {
            // Canvas not yet mounted — guard against a null/no-op download.
            return;
        }
        const dataUrl = canvas.toDataURL('image/png');
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = fileName;
        link.click();
    };

    const handleCopy = async () => {
        try {
            if (!navigator.clipboard) {
                throw new Error('Clipboard API unavailable');
            }
            await navigator.clipboard.writeText(url);
            toast.success('Link copied!');
        } catch {
            toast.error('Could not copy the link. Please copy it manually.');
        }
    };

    return (
        <div className="flex flex-col items-start gap-4">
            {/* One high-res canvas, displayed small via CSS, downloaded at full size. */}
            <div ref={containerRef} className={className ?? 'w-full max-w-[240px]'}>
                <QRCodeCanvas
                    value={url}
                    size={1024}
                    marginSize={4}
                    bgColor="#ffffff"
                    fgColor="#000000"
                    level="M"
                    className="h-auto w-full"
                />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button onClick={handleDownload}>
                    <Download className="mr-2 size-4" />
                    Download QR Code
                </Button>
                <Button variant="outline" onClick={handleCopy}>
                    <Copy className="mr-2 size-4" />
                    Copy Link
                </Button>
                <Button asChild variant="outline">
                    <a href={url} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="mr-2 size-4" />
                        Open Event
                    </a>
                </Button>
            </div>
        </div>
    );
}
```

Notes:

- **Download** reads the canvas via a ref, converts to a PNG data URL (`canvas.toDataURL('image/png')`), and triggers an anchor with `download={fileName}`. If the canvas is not mounted, the handler is a safe no-op.
- **Copy** uses `navigator.clipboard.writeText(url)` inside `try/catch`, guarding against `navigator.clipboard` being undefined (non-secure contexts). Success → `toast.success('Link copied!')`; failure → `toast.error(...)`. The page never crashes.
- **Open Event** uses `<Button asChild>` (Radix Slot) wrapping an anchor to `url` with `target="_blank"` and `rel="noopener noreferrer"`.
- The QR encodes **only** the `url` prop; there is no other data source.

### Frontend — `Events/Show.tsx` QR Section

The existing page maps a `comingSoonSections = ['QR Code', 'Photo Gallery', 'Uploads']` array to three "Coming Soon" cards. This feature:

- Removes `'QR Code'` from `comingSoonSections`, leaving `['Photo Gallery', 'Uploads']` unchanged as Coming Soon.
- Adds a real QR section as its own `Card` in that grid, before or beside the remaining placeholders.
- Extends the props interface from `{ event: Event }` to `{ event: Event; publicUrl: string }`.
- Updates the `.layout` resolver argument type to `(props: { event: Event; publicUrl: string })`. Breadcrumbs only reference `props.event`, so the body is unchanged; only the type widens.

Representative QR section:

```tsx
import QrCode from '@/components/QrCode';

interface Props {
    event: Event;
    publicUrl: string;
}

const comingSoonSections = ['Photo Gallery', 'Uploads'] as const;

// inside the component, replacing the old "QR Code" card:
<Card>
    <CardHeader>
        <CardTitle>Event QR Code</CardTitle>
    </CardHeader>
    <CardContent className="flex flex-col gap-4">
        <p className="text-muted-foreground text-sm">
            Guests can scan this QR code to open your event page.
        </p>
        <QrCode url={publicUrl} fileName={`momentgather-${event.slug}-qr.png`} />
        <p className="text-muted-foreground break-all text-xs">{publicUrl}</p>
    </CardContent>
</Card>
```

The component signature becomes `export default function EventsShow({ event, publicUrl }: Props)`.

## Data Models

No data model changes. No migration, no new column, no new table. `publicUrl` is a request-time derived string, never persisted.

The frontend `Event` type is unchanged. The `Events/Show` page props gain a `publicUrl: string` field, delivered per request as an Inertia prop:

```ts
interface Props {
    event: Event;      // existing, unchanged
    publicUrl: string; // new, derived per request from event.slug
}
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

The properties below are the source of truth for correctness. The backend-anchored properties (P1, P3, P4, P6, P7) are verified with example-based PHPUnit feature tests using `AssertableInertia`; URL generation and authorization are deterministic, so a small set of representative examples fully exercises each universal statement — no property-based testing library is introduced. P2 and P5 describe client-side behavior; P5 is a pure string transformation that can be unit-tested, and P2 is anchored by the backend `publicUrl` value.

### Property 1: Public URL correctness

*For any* event, the `publicUrl` prop supplied to the organizer event page SHALL equal `route('public.events.show', ['slug' => $event->slug])`, SHALL contain the event's slug, and SHALL resolve to the path `/e/{slug}`.

**Validates: Requirements 1.2, 1.3, 1.4**

### Property 2: QR encodes exactly the public URL

*For any* rendered QR code, the encoded value SHALL equal the `url` prop supplied to the QR component, derived from no other source.

**Validates: Requirements 4.1, 4.3**

### Property 3: Authorization and payload

*For any* event, when the authenticated owner requests the organizer event page, the response SHALL be HTTP 200 rendering `Events/Show` with both the `event` prop and the `publicUrl` prop present; *for any* authenticated non-owner the response SHALL be HTTP 403; and *for any* unauthenticated visitor the response SHALL redirect to `/login`.

**Validates: Requirements 1.1, 1.5, 2.1, 2.2, 2.4**

### Property 4: Owner payload is status-independent

*For any* event owned by the requesting organizer, regardless of the event's status (`active`, `archived`, or `draft`), the organizer event page SHALL render with the `publicUrl` prop present.

**Validates: Requirements 10.1, 10.2**

### Property 5: Download filename derives from the slug

*For any* event slug, the download filename SHALL equal `momentgather-{slug}-qr.png`, derived from the event's slug rather than from raw user-entered text.

**Validates: Requirements 5.5, 5.6**

### Property 6: No persistence and no schema change

*For any* request to the organizer event page, no QR image and no QR-related value SHALL be written to the database, and the `events` table SHALL contain no QR-related column.

**Validates: Requirements 8.2, 8.3**

### Property 7: Public destination visibility parity

*For any* event, a request to its `publicUrl` SHALL return HTTP 200 rendering `Public/Event` if and only if the event's status is `active` and the event is not soft-deleted; otherwise (archived, draft, or soft-deleted) the request SHALL return HTTP 404.

**Validates: Requirements 9.2, 9.3, 9.4**

## Error Handling

| Scenario | Handling | Requirement |
| --- | --- | --- |
| Clipboard API unavailable (`navigator.clipboard` undefined, non-secure context) | Guard throws inside `try`; caught → `toast.error(...)`; page continues | 6.3 |
| Clipboard write rejects/throws | Caught in `try/catch` → `toast.error(...)`; no crash | 6.3 |
| Download activated before canvas mounts | `containerRef.current?.querySelector('canvas')` returns null → handler returns as a no-op | 5.1 |
| Non-owner requests organizer page | `EventPolicy@view` returns false → `authorize` aborts with HTTP 403 | 2.2 |
| Guest requests organizer page | `auth` middleware redirects to `/login` | 2.4 |
| Guest opens `publicUrl` for archived/draft/soft-deleted event | Phase 3 `PublicEventController@show` `firstOrFail()` → HTTP 404 | 9.3, 9.4 |

## Testing Strategy

### Approach

- **Backend feature tests (PHPUnit)** verify the payload that everything else derives from: the presence and correctness of the `publicUrl` prop, authorization outcomes, status-independence, and the unchanged public-destination behavior. These use `#[Test]` attributes, `RefreshDatabase`, the in-memory SQLite connection, `AssertableInertia`, and `Event::factory()` / `User::factory()`, matching the conventions in `tests/Feature/EventTest.php`.
- **Client-side behaviors** — PNG download, canvas rendering, clipboard interaction, QR encoding by `qrcode.react`, and responsive layout — are validated by component structure and manual verification, not backend feature tests. The `qrcode.react` library's rendering is trusted (tested by its authors).
- **No property-based testing library** is introduced. URL generation and authorization are deterministic; example-based tests over representative events fully cover the universal properties. The download-filename transformation (Property 5) is a pure helper that can be unit-tested client-side if desired.

### Backend tests — `tests/Feature/EventQrCodeTest.php`

| Test method | Asserts | Property / Requirement |
| --- | --- | --- |
| `owner_receives_public_url_prop_on_show` | Owner GET `/events/{uuid}` → 200, `AssertableInertia` component `Events/Show`, `publicUrl` prop present and equal to `route('public.events.show', ['slug' => $event->slug])` | P1, P3 / 1.1, 1.2 |
| `public_url_contains_slug_and_e_path` | `publicUrl` contains `$event->slug` and the `/e/` path segment (ends with `/e/{slug}`) | P1 / 1.3, 1.4 |
| `show_payload_still_includes_event_data` | Both `event` and `publicUrl` props present in the `Events/Show` payload | P3 / 1.5 |
| `non_owner_cannot_view_event_show` | Authenticated non-owner GET show → 403 (QR-specific assertion; complements existing update/delete 403 tests in `EventTest`) | P3 / 2.2 |
| `guest_redirected_from_event_show` | Guest GET `/events/{uuid}` → redirect to `/login` | P3 / 2.4 |
| `owner_receives_public_url_prop_regardless_of_status` | Owner GET show for owned `archived` and `draft` events → 200 with `publicUrl` prop present | P4 / 10.1, 10.2 |
| `public_url_resolves_for_active_event_returns_200` | GET the `publicUrl` for an active event → 200 rendering `Public/Event` | P7 / 9.2 |
| `public_url_for_archived_event_returns_404` | GET `publicUrl` for an archived event → 404 | P7 / 9.3 |
| `public_url_for_draft_event_returns_404` | GET `publicUrl` for a draft event → 404 | P7 / 9.3 |
| `public_url_for_soft_deleted_event_returns_404` | Soft-delete an active event, GET `publicUrl` → 404 | P7 / 9.4 |
| `viewing_show_page_persists_no_qr_data` | After owner GET show, no schema/data change; `events` table has no QR column (schema assertion) | P6 / 8.2, 8.3 |

### Client-side verification (manual / structural)

- QR section displays heading "Event QR Code" and supporting text "Guests can scan this QR code to open your event page." (Req 3.1, 3.2).
- The QR section renders the `QrCode` component with `url={publicUrl}` and shows `publicUrl` as readable text (Req 3.3, 3.4).
- Download, Copy Link, and Open Event controls are present; the "QR Code" placeholder card is replaced while Photo Gallery and Uploads remain Coming Soon (Req 3.5, 3.6).
- `QRCodeCanvas` is configured `size={1024}`, `marginSize={4}`, `bgColor="#ffffff"` (Req 5.2, 5.3, 5.4).
- Copy success shows "Link copied!"; clipboard failure shows an error toast without crashing (Req 6.2, 6.3).
- QR display is constrained (e.g. `max-w-[240px]`, `w-full` within that bound) and controls wrap on small viewports (Req 11.1, 11.2).
- `package.json` declares `qrcode.react`; no server-side QR library is used (Req 12.1, 12.2).

### Filename safety

The download filename is built as `momentgather-${event.slug}-qr.png`. The slug is generated and sanitized at event creation (`SlugGenerator`), so it is already URL- and filesystem-safe. The filename never derives from raw user-entered text such as the event name.

### Responsive design

The QR canvas is displayed at a constrained width (e.g. `max-w-[240px]` with `w-full`) so it remains crisp and scannable on small viewports without occupying the full viewport width. The control buttons use `flex flex-wrap gap-2` so they wrap gracefully on mobile. Because the underlying canvas is 1024 px, downscaling for display keeps the on-screen QR sharp across all breakpoints.
