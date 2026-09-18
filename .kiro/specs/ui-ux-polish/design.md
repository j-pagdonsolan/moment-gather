# Design Document

## Overview

Phase 11 (UI/UX Polish) makes the existing MomentGather experience feel finished, consistent, and mobile-first. It is **polish, not rebuild**: every change refines a file that already works, reuses the existing shadcn/ui primitives in `resources/js/components/ui`, and adds new reusable components only where duplication is real. The stack is unchanged (Laravel 13 + React 19 + Inertia v3 + TypeScript + Tailwind v4 + shadcn/ui, local-only).

Guiding constraints for this design:

- **Mobile-first, guest-facing priority.** The public event page, uploader, gallery, and photo viewer are the primary surfaces (attendees on phones at 375–412px). Organizer surfaces are secondary.
- **Reuse primitives.** No new UI library or CSS framework. New components are limited to a `Textarea` primitive and (optionally) a small shared `EmptyState`.
- **One allowed backend touch.** The only backend edit is adding `Inertia::flash('toast', ['type' => 'success', 'message' => ...])` to `EventController::store()`, `update()`, and `destroy()` immediately before their existing redirect returns. Nothing else on the backend changes. `Inertia\Inertia` is already imported in that controller, so no new `use` statement is required.
- **No JS test framework.** Regression is guarded by the existing 195-test backend suite (`php artisan test`), plus clean `npx tsc --noEmit` and `npm run build`.
- **Preserve Phases 1–10.** No working behavior changes except the additive toast flashes.
- **Page navigation progress bar is already present.** `app.tsx` configures the Inertia progress bar (`progress: { color: '#4B5563' }`). R10.1 is therefore already satisfied; this design does not re-add it and only notes its presence.

The flash-toast infrastructure already exists end to end (`components/ui/sonner.tsx` mounts the `sonner` Toaster and calls `useFlashToast()`; `hooks/use-flash-toast.ts` listens on `router.on('flash')`, reads `detail.flash.toast`, and calls `toast[type](message)`; `types/ui.ts` defines `FlashToast`). Settings controllers already flash toasts. The `EventController` does not — closing that gap is the single backend change (R8.5/R8.6, R11.2).

## Architecture

### Layout split (guest vs organizer)

`app.tsx` resolves layouts by page name and this split is preserved:

- **Guest / standalone** — `welcome` and `Public/*` resolve to `null` layout. Public pages (`Public/Event.tsx`, `Public/Gallery.tsx`) render their own centered column with no sidebar. All guest polish stays inside these standalone pages and the shared guest components (`PhotoUploader`, `PhotoGrid`, `PhotoCard`, `PhotoViewer`).
- **Auth** — `auth/*` uses `AuthLayout`.
- **Organizer** — `settings/*` uses `[AppLayout, SettingsLayout]`; everything else uses `AppLayout` (sidebar shell → `app-sidebar-layout`). Dashboard, Events Index/Show/Create/Edit live here.

`withApp` wraps the app in `TooltipProvider` + `<Toaster />`, so toasts are available on every surface without per-page wiring.

### Flash-toast data flow (organizer notifications)

```
EventController::store/update/destroy
  → Inertia::flash('toast', ['type' => 'success', 'message' => 'Event created.'])
  → redirect (to_route(...))                     // existing, unchanged
        │
        ▼
Inertia response includes flash.toast
  → router 'flash' event
  → useFlashToast() reads detail.flash.toast {type, message}
  → toast[type](message)
  → sonner Toaster renders (bottom-right)
```

This reuses the exact mechanism the Settings controllers already use, so organizer create/update/delete produce a single success toast each (R11.1, R11.2, R11.4). Guest photo uploads keep their existing **inline** success message and do NOT route through flash-toast (R11.3). `QrCode.tsx` keeps its direct `toast.success/error` calls (R9.4).

### Where new reusable components live

To keep new-component count minimal:

- **`resources/js/components/ui/textarea.tsx`** — required. A `Textarea` primitive modeled on the existing `Input` primitive shape, replacing the raw `<textarea>` block duplicated across `Events/Create.tsx` and `Events/Edit.tsx` (R1.3, R1.4).
- **`resources/js/components/empty-state.tsx`** — optional but recommended. A small presentational component (icon + title + description + optional CTA slot) used by the Gallery empty state, the Dashboard empty state, and the Events Index empty state. Introduced only because the empty-state pattern recurs on three surfaces (R1.3, R5.3, R7.5, R10.3).
- **StatusBadge** — NOT introduced. The status → `Badge variant` mapping appears in few places and is trivial; extracting it would not reduce meaningful duplication. Existing `Badge` is used directly.

No other new components. `PhotoCard`, `PhotoGrid`, `QrCode`, `sonner`, and all layouts remain structurally as-is.

### Styling approach

All spacing, typography, and color come from existing Tailwind v4 tokens and shadcn/ui primitives (R1.1, R1.2, R1.5). Guest-facing body text targets ≥16px (`text-base`), and interactive controls target ≥44×44px touch area via padding/size utilities (R2.3, R2.4). Responsiveness uses mobile-first Tailwind breakpoints (`sm:`, `md:`, `lg:`).

## Components and Interfaces

Legend: **TOUCHED** = file modified this phase. **NEW** = file created this phase. **VERIFIED** = read and confirmed correct, left unchanged.

### Backend

#### `app/Http/Controllers/EventController.php` — TOUCHED (R8.5, R8.6, R11.2, R11.5)

Add one flash line immediately before each existing redirect. No other change; `use Inertia\Inertia;` is already present.

- `store()`: before `return to_route('events.show', $event);`
  `Inertia::flash('toast', ['type' => 'success', 'message' => 'Event created.']);`
- `update()`: before `return to_route('events.show', $event);`
  `Inertia::flash('toast', ['type' => 'success', 'message' => 'Event updated.']);`
- `destroy()`: before `return to_route('events.index');`
  `Inertia::flash('toast', ['type' => 'success', 'message' => 'Event deleted.']);`

Validation, authorization (`$this->authorize`), the DB transaction in `store`, routes, and redirect targets are unchanged. Existing tests that assert redirect status/location are unaffected because `Inertia::flash` only adds session flash data (R11.5, R16.1).

### New components

#### `resources/js/components/ui/textarea.tsx` — NEW (R1.4)

Purpose: reusable multi-line text input replacing the duplicated raw `<textarea>`.

```ts
function Textarea({ className, ...props }: React.ComponentProps<'textarea'>): JSX.Element
```

- Renders `<textarea data-slot="textarea" className={cn(base, className)} {...props} />`.
- `cn` from `@/lib/utils`.
- Base classes mirror the existing `Input` primitive tokens plus multi-line sizing: `border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 aria-invalid:border-destructive flex min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm` (this is the exact class string currently inlined in Create/Edit, promoted into the primitive).
- Forwards all native textarea props (`id`, `name`, `value`, `onChange`, `maxLength`, `rows`, `required`, `aria-invalid`).

#### `resources/js/components/empty-state.tsx` — NEW, optional (R5.3, R7.5, R10.3)

Purpose: consistent empty-state presentation across Gallery, Dashboard, and Events Index.

```ts
interface EmptyStateProps {
  icon?: LucideIcon;      // e.g. ImageOff, CalendarPlus
  title: string;
  description?: string;
  action?: ReactNode;     // slot for a Button/Link Tap_Target
  className?: string;
}
```

- Renders a centered stack (icon, title `text-base`/semibold, muted description, action slot) using existing tokens. Semantic markup with an accessible heading (R12.1). No data fetching, no state.

### Guest-facing pages/components (priority)

#### `resources/js/pages/Public/Event.tsx` — TOUCHED (R3.1–R3.7, R2.1, R2.3, R2.4)

- Replace the empty `aspect-video bg-muted` cover box with a polished hero band (event name h1, uppercase eyebrow, friendly date, location) — still `aria-hidden` decorative where appropriate; keep the standalone `mx-auto max-w-md/lg` column (R3.1).
- Keep optional description rendering (R3.2).
- Add a **Scan → Upload → Done** progression (three numbered steps with icons/labels) that visually communicates the guest flow (R3.3).
- When `upload_enabled` is true, render a prominent upload CTA leading into `PhotoUploader`; when false, render the uploads-closed message and NO uploader controls (R3.4, R3.5).
- Keep the outline `Button` link to `/e/{slug}/gallery` as a full-width Tap_Target labeled with `photoCount` (e.g. "View Gallery — {photoCount} photos") (R3.6).
- Mobile-first: single column, ≥16px body text, ≥44px tap targets, no horizontal overflow at 375px (R2.1, R2.3, R2.4).
- No prop changes; `PublicEvent` already carries `photoCount`. No auth or active-only logic touched (backend enforcement preserved, R3.7).

#### `resources/js/components/PhotoUploader.tsx` — TOUCHED (R4.1–R4.9, R10.4, R10.5, R12.9)

- Keep `useForm<{ photos: File[] }>`, the hidden `<input type="file" accept="image/jpeg,image/png,image/webp" multiple>`, object-URL previews (3-col grid) with remove control, error bag, `rateLimited` (429) friendly message, inline success `<p>` after `onSuccess`, and `post('/e/${slug}/photos', { forceFormData: true, preserveScroll: true, ... })` (R4.1, R4.2, R4.5, R4.6, R4.9).
- Polish: larger tap targets for "Select Photos" and per-preview remove `X` (≥44px); disable the upload control and show `progress.percentage` while in flight (R4.3, R13.4); clearer inline success (R4.4).
- Retry: on failure, keep the pending selection intact and expose a retry affordance (re-enable the upload button / "Try again") so the guest can resubmit without re-selecting (R4.7).
- Optional desktop enhancement: drag-and-drop onto the drop zone (dragover/drop handlers appending to the same `photos` selection); progressive enhancement only, file picker remains primary (R4.8).
- Accessibility: `aria-busy` / live region for progress and success (R12.9); accessible name on the remove control.
- Accepted types and backend validation unchanged (R4.9).

#### `resources/js/pages/Public/Gallery.tsx` — TOUCHED (R5.1–R5.7, R2.1, R10.2, R10.3)

- Keep state (`items/currentPage/lastPage/loading/viewerIndex`), `hasMore`, and `loadMore` via `router.get('/e/${slug}/gallery', { page }, { only: ['photos','pagination'], preserveState, preserveScroll, onSuccess dedupe by uuid, onFinish })` (R5.5, R13.3, R13.5).
- Add **skeleton placeholders** (existing `skeleton` primitive) in the grid region while `loading` is true on load-more (R5.2, R10.2, R12.9).
- Polish the **empty state** using the new `EmptyState` with an upload Tap_Target linking back to the event page (R5.3, R10.3).
- Keep `PhotoGrid`, broken-image fallback, only-ready enforcement, lazy loading, and `PhotoViewer` mount when `viewerIndex !== null` (R5.4, R5.6, R5.7).
- Mobile spacing pass; no horizontal overflow at 375px (R2.1).

#### `resources/js/components/PhotoViewer.tsx` — TOUCHED, refactor (R6.1–R6.8, R2.5, R12.5–R12.7)

Refactor the hand-rolled `fixed inset-0` overlay to use the existing **Radix `Dialog`** (`components/ui/dialog.tsx`) for free focus trap, body-scroll-lock, focus restore, `role="dialog"` + `aria-modal`, and Escape handling.

- Render `<Dialog open onOpenChange={(o) => !o && onClose()}>` with a full-bleed `DialogContent` styled via `className` to override the default centered card: full width/height, black background, no border/padding (Radix `DialogContent` accepts a custom `className` and merges it via `cn`, so a full-screen presentation is supported).
- Provide a `DialogTitle` (visually hidden via `sr-only` if needed) for an accessible name (R6.5, R12.3).
- Keep: optimized image (`src={optimizedUrl}` constrained `max-h`/`max-w` to fit viewport without clipping, R2.5, R2.6, R13.2), Prev/Next controls and top-right Close as large Tap_Targets (≥44px, R6.2), Download `<a href="/e/{slug}/photos/{uuid}/download">` unchanged (R6.3), and `ArrowLeft/ArrowRight` key handling for navigation (R6.4). Escape/focus-trap/scroll-lock/focus-restore now come from Radix (R6.6, R6.7, R12.5, R12.6).
- Add touch **swipe** handlers (`onTouchStart/onTouchEnd`, horizontal threshold) to navigate prev/next on touch devices (R6.8).
- Props unchanged: `{ photos, index, slug, onIndexChange, onClose }`. The keydown effect for arrows can remain (Radix handles Escape); avoid double-handling Escape.

Rationale for choosing Radix over hardening the hand-rolled overlay is in Design Decisions.

#### `resources/js/components/PhotoGrid.tsx` — VERIFIED, unchanged (R5.1)

`grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2` → `PhotoCard`. Responsive column count already correct.

#### `resources/js/components/PhotoCard.tsx` — VERIFIED, unchanged (R5.4, R5.7, R12.4, R13.1)

`aspect-square` button, `<img loading="lazy" onError → broken (ImageOff + "Photo unavailable")>`, `group-hover:scale-105`. Lazy loading and broken-image fallback are already correct; kept as-is.

### Organizer pages

#### `resources/js/pages/dashboard.tsx` — TOUCHED (R7.1–R7.7, R10.3)

- Keep 3 stat cards, Recent Events card, and Create Event link (R7.1, R7.4).
- Improve scannability (spacing/typography); render a status `Badge` per recent event using the existing primitive (R7.2).
- Empty state (no events) uses `EmptyState` with a create-event Tap_Target (R7.5, R10.3).
- **Photo count is NOT added.** `recentEvents` is `Pick<Event, 'uuid'|'name'|'status'|'event_date'|'created_at'>[]` and carries no count; adding one requires a backend query, which is out of scope. R7.3 ("WHERE the Ready_Photo_Count is available") is satisfied by omission here (not available → not displayed). See Data Models.
- Loading is covered by the existing page-level progress bar; disabled/loading affordances added where actions are async (R7.6, R12.9). No new business functionality (R7.7).

#### `resources/js/pages/Events/Index.tsx` — TOUCHED (R2.2, R2.1, R2.3, R10.3)

- Redesign to a **responsive card list**: cards stacked on mobile (`<sm`) so there is no horizontally scrolling table at 375px (R2.2, R2.1). On `sm+`, either retain the table or use the same card list for consistency (card list preferred for a single responsive path).
- Each card shows Name, Date, Location, status `Badge`, Uploads, Created, and Actions as ≥44px Tap_Targets (R2.3).
- Keep the delete confirmation `Dialog`, existing routes, and `Badge` usage unchanged.
- Empty state uses `EmptyState` with a create Tap_Target (R10.3).

#### `resources/js/pages/Events/Show.tsx` — TOUCHED (R15.1–R15.4, R2.1, R9.1)

- **Remove** the `comingSoonSections = ['Photo Gallery','Uploads']` placeholder cards (R15.1).
- Replace with real Tap_Targets: a link to the public event page (`publicUrl`) and a link to the Gallery (`/e/{slug}/gallery`) (R15.2, R15.3).
- Keep the Event Details card, the QR card (add event-title context to the QR card header for R9.1), and the delete/edit controls + delete `Dialog`.
- **Ready_Photo_Count on Show (R15.4): deferred.** The `event` prop (`Event` type) has no photo count and `EventController@show` passes only `event` + `publicUrl`. Adding a count requires a backend change beyond the allowed toast touch, so this design does NOT add it. The gallery/public links are provided without a numeric count; R15.4 is documented as deferred pending a future backend-permitted phase. (Note: the public event page still shows `photoCount` via its own `PublicEvent` prop, satisfying R3.6.)
- Mobile: stack cards, no horizontal overflow at 375px (R2.1).

#### `resources/js/pages/Events/Create.tsx` — TOUCHED (R8.1–R8.7, R1.4)
#### `resources/js/pages/Events/Edit.tsx` — TOUCHED (R8.1–R8.7, R1.4)

- Replace the raw `<textarea>` (with the long inline className duplicated across both files) with the new `Textarea` primitive; the status `<select>` in `Edit.tsx` may stay as-is or move to the existing `Select` primitive (optional consistency; not required). (R1.4)
- Keep `useForm`, `Heading`, `Label` + `Input` + `InputError`, `Create` posting `eventsStore()`, `Edit` doing `put(eventsUpdate)` with its status select (R8.1, R8.3).
- Mark required fields visually (e.g. label with a required indicator) for `name` (R8.2).
- Keep `disabled={processing}` on submit and reflect a loading state on the button to prevent duplicate submissions (R8.4, R13.4).
- Success feedback comes from the backend flash on redirect to Show (R8.5, R8.6) — no client toast in the forms.
- Usable inputs at ≥375px (R8.7, R2.4).

#### `resources/js/components/QrCode.tsx` — VERIFIED / QR card TOUCHED via Show (R9.1–R9.6)

- `QrCode` keeps direct `toast.success/error` for copy/open/download confirmation (R9.4) and existing download behavior (R9.3). Unchanged internally.
- The **QR card in `Events/Show.tsx`** gains event-title context in its header (R9.1) and continues to show `publicUrl` as text (R9.2). Ensure the card and controls have no horizontal overflow at 375px (R9.6). Print-friendly presentation is optional and MAY be added via a print stylesheet/utility (R9.5).

### Auth pages

#### `resources/js/pages/auth/*` — TOUCHED (consistency pass only) (R14.1–R14.5)

- Consistency pass using `AuthLayout` and existing primitives (Label/Input/InputError/Button): confirm validation errors render (R14.2), submit shows a loading state and disables while processing (R14.3), and inputs are usable at ≥375px (R14.4).
- **No authentication behavior changes** (R14.5). This is styling/state polish only.

### Infrastructure (verified, unchanged)

- `resources/js/app.tsx` — VERIFIED. Layout resolver + `withApp` (`TooltipProvider` + `<Toaster />`) + progress bar (`#4B5563`) already correct (R10.1). Not modified.
- `resources/js/components/ui/sonner.tsx`, `hooks/use-flash-toast.ts`, `types/ui.ts` — VERIFIED. Toast pipeline correct; not modified (the only change needed is the backend flash).

## Data Models

**No backend data, schema, model, query, or migration changes.** The only backend edit is the three additive `Inertia::flash('toast', ...)` lines in `EventController` (R11.5, R16.4). No new controllers, no changed Inertia props.

Confirmed prop/type facts driving the design:

- `Event` (`resources/js/types/models.ts`) has: `id, uuid, user_id, name, slug, description, event_date, location, status, upload_enabled, created_at, updated_at, deleted_at`. **No photo count.**
- `EventController@show` passes `{ event, publicUrl }` only — **no count** → R15.4 (photo count on Show) is deferred (no backend query added).
- Dashboard `recentEvents` is `Pick<Event, 'uuid'|'name'|'status'|'event_date'|'created_at'>` — **no count** → R7.3 is satisfied by omission (count not available on this surface).
- `PublicEvent.photoCount: number` **exists** → R3.6 is fully satisfiable on the public event page with no backend change.
- `GalleryPhoto` / `GalleryPageProps` / `GalleryPagination` already carry everything the gallery, viewer, and load-more need (`uuid, thumbnailUrl, optimizedUrl, filename, width, height`, `current_page`, `last_page`).
- `FlashToast` type (`types/ui.ts`) already matches the `{type, message}` payload the backend will flash.

No client-side type additions are required beyond the props for the two new components (`EmptyStateProps`, and the standard `React.ComponentProps<'textarea'>` for `Textarea`).

## Error Handling

- **Generic, safe messages.** All user-facing errors exclude stack traces, SQL, filesystem paths, and internal identifiers (R10.6). This is preserved from existing backend responses and the frontend renders only server-provided validation messages and friendly copy.
- **Upload validation errors.** `PhotoUploader` continues to render the `useForm` error bag as readable `text-destructive` text (R4.5, R10.5).
- **Rate limiting (429).** `PhotoUploader` keeps its `onHttpException` 429 path showing a friendly rate-limit message (R4.6). No rate-limit behavior changes.
- **Upload failure / retry.** On failure the pending selection is preserved and a retry affordance is offered (R4.7).
- **Inactive event / unauthorized.** Enforced entirely by existing backend responses (public active-only, `EventPolicy` authorize calls). The UI surfaces the resulting generic error; no client-side authorization logic is added (R3.7, R10.5).
- **Broken images.** `PhotoCard` keeps its `onError` fallback (ImageOff + "Photo unavailable") for thumbnails (R5.4). The viewer's optimized image degrades gracefully if it fails to load.
- **Failed download.** The download uses the existing endpoint via a plain `<a href>`; failures are handled by the browser/backend response, and any organizer-side copy/open feedback stays on the existing `QrCode` toasts (R9.4, R10.5).

## Testing Strategy

**No JS/frontend test framework is introduced** (out of scope, consistent with Phase 10). Regression is guarded by three automated gates plus a manual UI matrix.

### Automated regression gates (R16.1–R16.3)

1. `php artisan test` — the full existing suite (195 tests) MUST stay green. The toast touch adds only session flash data; it does not change validation, authorization, routes, or redirect targets, so `EventTest`, `EventValidationTest`, etc. (which assert redirect status/location) are unaffected. `Inertia::flash` does not change the redirect response.
2. `npx tsc --noEmit` — MUST report no type errors (new `Textarea`/`EmptyState` components and all touched pages type-clean).
3. `npm run build` — MUST complete without build errors.

### Manual UI testing matrix (no automation)

Test in Chrome at **375px, 390px, 412px** and desktop. For each: verify no horizontal page-body overflow, tap targets ≥44px, and body text ≥16px on guest surfaces.

Acceptance-criteria-derived manual checks:

- **Public event page (R3):** name/date/location render; description shows when present; Scan→Upload→Done visible; upload CTA prominent when `upload_enabled`; closed message + no uploader when disabled; gallery link shows `photoCount`; page loads without auth for an active event.
- **Uploader (R4):** select shows previews; remove drops a file; upload disables control and shows %; success message appears; validation errors render as text; 429 shows friendly copy; failed upload can be retried; desktop drag-and-drop (if implemented) appends files; only JPEG/PNG/WebP selectable.
- **Gallery (R5, R10.2):** responsive columns; skeletons appear during load-more; empty state with upload CTA; broken-image fallback; load-more appends without duplicates.
- **Photo viewer (R6, R12.5–R12.7):** opens full-screen with optimized image; close/prev/next work as tap targets; download works; Escape + arrow keys on desktop; focus trapped and page scroll locked while open; focus restored on close; swipe navigates on touch; content fits viewport without clipping at 375px.
- **Dashboard (R7):** stats + recent events; status badges; create Tap_Target; empty state with create CTA; loading indicator on navigation.
- **Events Index (R2.2):** card layout on mobile, no horizontal scroll; delete dialog still works; badges render.
- **Event Show (R15, R9):** no Coming Soon cards; working links to public page and gallery; QR card shows event title context + public URL; no overflow at 375px.
- **Create/Edit forms (R8):** labels present; required fields marked; validation errors via InputError; submit disabled + loading while processing; success toast after create/update (from backend flash); usable at 375px; `Textarea` renders in both forms.
- **Toasts (R11):** exactly one success toast on create/update/delete; guest upload uses inline success (no organizer toast).
- **Auth pages (R14):** consistent styling; validation errors; submit loading state; usable at 375px; sign-in/register/reset behavior unchanged.
- **Accessibility spot-check (R12):** keyboard operates all controls with visible focus; inputs have labels; images have alt text; modal focus management and Escape.

## Design Decisions

### 1. Photo Viewer: refactor to Radix Dialog (chosen) vs harden hand-rolled overlay

**Decision:** Refactor `PhotoViewer` to use the existing Radix `Dialog` with a full-bleed, black-styled `DialogContent`.
**Rationale:** The requirements demand focus trap, `role="dialog"`/`aria-modal`, body-scroll-lock, focus restore, and Escape (R6.5–R6.7, R12.5–R12.6). Radix `Dialog` provides all of these for free and is already a project primitive, so this reduces hand-written a11y code and risk. `DialogContent` merges a custom `className` via `cn`, so overriding the default centered card into a full-screen viewer is straightforward. We keep prev/next/download, arrow-key navigation, optimized-image usage, and add swipe. Hardening the hand-rolled overlay would mean re-implementing focus trapping and scroll-lock by hand — more code and more ways to get a11y wrong.

### 2. `Textarea` primitive

**Decision:** Add `components/ui/textarea.tsx` and use it in Create/Edit.
**Rationale:** The identical long `<textarea>` className is duplicated across both forms (R1.3, R1.4). Promoting it to a primitive removes duplication and matches the existing `Input` primitive pattern.

### 3. No backend photo counts on Dashboard or Event Show

**Decision:** Do not add Ready_Photo_Count to the dashboard or Event Show.
**Rationale:** Neither `recentEvents` nor the `Event` prop on Show carries a count, and `EventController@show` passes none. Displaying one requires a backend query, which exceeds the single allowed backend touch (the toast flash). R7.3 is satisfied by omission ("where available"); R15.4 is documented as **deferred**. The public event page still satisfies R3.6 because `PublicEvent.photoCount` already exists.

### 4. Responsive cards for Events Index

**Decision:** Replace the horizontally scrolling table with a responsive card list (cards on mobile, consistent path on larger screens).
**Rationale:** R2.2 explicitly requires cards at ≥375px with no horizontal overflow. The delete dialog, routes, and badges are preserved.

### 5. Organizer toasts via backend flash; guest uploads keep inline success

**Decision:** Route organizer create/update/delete confirmations through the existing `Inertia::flash` → `useFlashToast` → `sonner` pipeline; leave guest upload success inline.
**Rationale:** R11.1–R11.4. This uses the established Settings-controller pattern, guarantees one toast per action, and avoids over-notifying guests, whose upload feedback is better inline in the uploader.

### 6. Minimal new components

**Decision:** Add only `Textarea` (required) and `EmptyState` (optional, shared across three surfaces). Skip a `StatusBadge`.
**Rationale:** R1.3 asks for extraction only where duplication is significant. Empty states recur three times; the status-badge mapping is trivial and localized, so extracting it would add indirection without meaningful de-duplication.

### 7. Keep PhotoCard and PhotoGrid as-is; progress bar already present

**Decision:** Leave `PhotoCard` (lazy loading + broken-image fallback) and `PhotoGrid` (responsive columns) unchanged, and do not re-add the page-navigation progress bar.
**Rationale:** These already satisfy R5.1, R5.4, R5.7, R13.1, and R10.1. `app.tsx` already configures the Inertia progress bar; re-adding it would be redundant.
