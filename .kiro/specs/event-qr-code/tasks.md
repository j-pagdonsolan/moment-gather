# Implementation Plan: Event QR Code (Phase 4)

## Overview

This plan implements client-side QR code generation for events. The QR code points to each event's existing public page at `/e/{slug}`. The work is small and incremental: add one npm dependency, add one Inertia prop from the backend, build one reusable React component, wire it into the existing organizer event page (replacing the "QR Code" Coming Soon card), and cover the backend payload/authorization behavior with feature tests.

Backend changes are PHP (Laravel). Frontend changes are TypeScript/React (Inertia). No new routes, no new controller, no migration, no database column.

Client-side behaviors — PNG download, canvas rendering, clipboard interaction, QR encoding, and responsive layout — are validated by component structure and manual verification per the design's Testing Strategy, not by backend feature tests.

## Tasks

- [ ] 1. Add the client-side QR dependency
  - Add `qrcode.react` to the `dependencies` section of `package.json`
  - Run `npm install` to install the package and update the lockfile
  - Confirm the package is React 19 compatible and its TypeScript types resolve
  - _Requirements: 12.1, 12.2_

- [ ] 2. Add the `publicUrl` prop from the backend
  - Edit `app/Http/Controllers/EventController.php` `show()` to add `'publicUrl' => route('public.events.show', ['slug' => $event->slug])` alongside the existing `'event' => $event` in the `Inertia::render('Events/Show', [...])` call
  - Keep `$this->authorize('view', $event)` unchanged (authorization stays delegated to `EventPolicy@view`)
  - Do not add any new route, controller, policy, migration, or database column
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.3, 10.1, 10.2_

- [ ] 3. Build the reusable `QrCode` component
  - Create `resources/js/components/QrCode.tsx` with props `{ url: string; fileName: string; className?: string }`
  - Render a single `QRCodeCanvas` (from `qrcode.react`) with `value={url}`, `size={1024}`, `marginSize={4}`, `bgColor="#ffffff"`, `fgColor="#000000"`, `level="M"`, displayed constrained (`max-w-[240px]`, `w-full`, `h-auto`)
  - Add a "Download QR Code" control: read the canvas via a ref, call `canvas.toDataURL('image/png')`, trigger an anchor with `download={fileName}`, and guard against a null/unmounted canvas as a safe no-op
  - Add a "Copy Link" control: guard `navigator.clipboard`, call `navigator.clipboard.writeText(url)` inside `try/catch`, show `toast.success('Link copied!')` on success and `toast.error(...)` on failure without crashing the page
  - Add an "Open Event" control: `<Button asChild>` wrapping `<a href={url} target="_blank" rel="noopener noreferrer">`
  - Use lucide icons (`Download`, `Copy`, `ExternalLink`), `Button` from `@/components/ui/button`, and `toast` from `sonner`; wrap buttons in `flex flex-wrap gap-2` so they wrap on small viewports
  - Encode the QR from the `url` prop only, with no other data source
  - _Requirements: 4.1, 4.3, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 6.1, 6.2, 6.3, 7.1, 7.2, 8.1, 11.1, 11.2_

- [ ] 4. Wire the QR section into the organizer event page
  - Edit `resources/js/pages/Events/Show.tsx`: widen the props interface from `{ event: Event }` to `{ event: Event; publicUrl: string }` and update the `.layout` resolver argument type to `{ event: Event; publicUrl: string }` (body unchanged — breadcrumbs only use `event`)
  - Destructure `publicUrl` in the component signature
  - Remove `'QR Code'` from the `comingSoonSections` array, leaving `['Photo Gallery', 'Uploads']`
  - Add a real QR `Card` with `CardTitle` "Event QR Code", supporting text "Guests can scan this QR code to open your event page.", `<QrCode url={publicUrl} fileName={`momentgather-${event.slug}-qr.png`} />`, and the `publicUrl` displayed as readable text (`break-all`)
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 4.2, 7.1, 11.1, 11.2_

- [ ] 5. Write backend feature tests for the QR payload and public destination
  - [ ] 5.1 Create `tests/Feature/EventQrCodeTest.php` covering the organizer payload and authorization
    - Use PHPUnit `#[Test]` attributes, `RefreshDatabase`, `AssertableInertia`, and `Event::factory()` / `User::factory()`
    - `owner_receives_public_url_prop_on_show`: owner GET show → 200, component `Events/Show`, `publicUrl` prop present and equal to `route('public.events.show', ['slug' => $event->slug])`
    - `public_url_contains_slug_and_e_path`: `publicUrl` contains the event slug and ends with the `/e/{slug}` path
    - `show_payload_still_includes_event_data`: both `event` and `publicUrl` props present in the `Events/Show` payload
    - `non_owner_cannot_view_event_show`: authenticated non-owner GET show → 403
    - `guest_redirected_from_event_show`: guest GET show → redirect to `/login`
    - `owner_receives_public_url_prop_regardless_of_status`: owner GET show for owned `archived` and `draft` events → 200 with `publicUrl` prop present
    - **Property 1: Public URL correctness; Property 3: Authorization and payload; Property 4: Owner payload is status-independent**
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.2, 2.4, 10.1, 10.2_
  - [ ] 5.2 Add public-destination visibility tests to `tests/Feature/EventQrCodeTest.php`
    - `public_url_resolves_for_active_event_returns_200`: GET the `publicUrl` for an active event → 200 rendering `Public/Event`
    - `public_url_for_archived_event_returns_404`: GET `publicUrl` for an archived event → 404
    - `public_url_for_draft_event_returns_404`: GET `publicUrl` for a draft event → 404
    - `public_url_for_soft_deleted_event_returns_404`: soft-delete an active event, GET `publicUrl` → 404
    - **Property 7: Public destination visibility parity**
    - _Requirements: 9.2, 9.3, 9.4_

- [ ] 6. Verification checkpoint
  - Run `php artisan test --filter=EventQrCodeTest`, then the full `php artisan test`, and fix any failures
  - Run `npx tsc --noEmit` and confirm `QrCode.tsx` and `Show.tsx` type-check and the `qrcode.react` types resolve
  - Run `php artisan route:list` and confirm no route changes were introduced
  - Manually verify the client-side-only behaviors that backend tests cannot cover: PNG download, canvas rendering, clipboard copy, responsive layout, and scanning the printed QR with a phone
  - Ensure all tests pass, ask the user if questions arise.
  - _Requirements: all_

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP. This plan has no optional sub-tasks: the feature test file is the primary correctness safeguard for the deterministic backend behavior, so it is treated as core work.
- Each task references specific requirements for traceability.
- The design includes Correctness Properties, but no property-based testing library is introduced. Properties P1, P3, P4, and P7 are verified with example-based PHPUnit feature tests (task 5); P2, P5, and P6 are client-side or schema concerns validated structurally/manually per the design's Testing Strategy.
- PNG download, clipboard, canvas rendering, and responsiveness are client-side and validated manually/structurally, not via backend tests.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "2"] },
    { "id": 1, "tasks": ["3"] },
    { "id": 2, "tasks": ["4", "5.1"] },
    { "id": 3, "tasks": ["5.2"] }
  ]
}
```
