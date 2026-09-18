# Implementation Plan: UI/UX Polish (Phase 11)

## Overview

This plan converts the Phase 11 UI/UX polish design into an incremental, dependency-ordered
sequence of coding tasks. It is **polish, not rebuild**: every task refines an existing file,
reuses the existing shadcn/ui primitives, and adds new reusable components only where the design
calls for them. The only backend edit permitted is the additive `Inertia::flash('toast', ...)`
touch in `EventController` (task 1.3).

The plan is structured around the design's **Components and Interfaces**:

- **Wave 0 (Foundation)** creates the two new reusable components (`Textarea`, `EmptyState`) and
  the additive backend toast touch. Everything else depends on these.
- **Wave 1 (Guest-facing)** polishes the priority guest surfaces (viewer, uploader, public event
  page, gallery) that consume the new `EmptyState`.
- **Wave 2 (Organizer)** polishes the organizer pages and forms that depend on `Textarea`,
  `EmptyState`, and the backend toast.
- **Wave 3 (QR + Auth)** finishes the QR presentation and auth consistency pass.
- **Wave 4 (A11y/Perf sweep)** is a cross-cutting verification+small-fix pass over touched files.
- **Wave 5 (Final verification)** runs the automated gates and the manual UI matrix.

Legend: **[NEW]** create a new file · **[MODIFY]** edit an existing file · **[VERIFY]** verification pass.
Regression is guarded by `php artisan test` + `npx tsc --noEmit` + `npm run build` (no JS test framework).

## Tasks

- [x] 1. Foundation: new reusable components and additive backend toast
  - [x] 1.1 [NEW] Create the `Textarea` design-system primitive
    - Create `resources/js/components/ui/textarea.tsx`, modeled on the existing `Input` primitive
      (`resources/js/components/ui/input.tsx`).
    - Render `<textarea data-slot="textarea" className={cn(BASE, className)} {...props} />`, importing
      `cn` from `@/lib/utils`; type as `React.ComponentProps<'textarea'>`.
    - `BASE` MUST be the exact class string currently inlined in Create/Edit:
      `border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 aria-invalid:border-destructive flex min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm`.
    - Forward all native textarea props (`id`, `name`, `value`, `onChange`, `maxLength`, `rows`, `required`, `aria-invalid`); `export { Textarea }`.
    - _Requirements: 1.4, 1.3_

  - [x] 1.2 [NEW] Create the shared `EmptyState` component
    - Create `resources/js/components/empty-state.tsx` as a presentational component with props
      `{ icon?: LucideIcon; title: string; description?: string; action?: ReactNode; className?: string }`.
    - Render a centered stack: optional icon, semibold `text-base` title as a semantic heading, muted
      description, and an `action` slot; use existing tokens; no data fetching, no state.
    - _Requirements: 1.3, 5.3, 7.5, 10.3_

  - [x] 1.3 [MODIFY] Add additive success flash toasts to `EventController` (only backend change)
    - In `app/Http/Controllers/EventController.php`, add
      `Inertia::flash('toast', ['type' => 'success', 'message' => 'Event created.']);` immediately before
      `store()`'s `return to_route('events.show', $event);`.
    - Add `'Event updated.'` flash immediately before `update()`'s `return to_route('events.show', $event);`.
    - Add `'Event deleted.'` flash immediately before `destroy()`'s `return to_route('events.index');`.
    - `use Inertia\Inertia;` is already imported; change NOTHING else (validation, `authorize`, DB transaction,
      routes, redirect targets all unchanged).
    - Verify after: `php artisan test --filter=EventTest` and `php artisan test --filter=EventValidationTest`
      remain green (`Inertia::flash` only adds session flash data and does not change redirect responses).
    - _Requirements: 8.5, 8.6, 11.2, 11.5_

- [x] 2. Guest-facing pages and components
  - [x] 2.1 [MODIFY] Refactor `PhotoViewer` onto the Radix Dialog primitive
    - In `resources/js/components/PhotoViewer.tsx`, wrap the viewer in `<Dialog open onOpenChange={(o)=>!o&&onClose()}>`
      from `components/ui/dialog`, with a full-bleed `DialogContent` whose `className` overrides the default centered
      card (full width/height, black background, no border/padding).
    - Add a visually-hidden `DialogTitle` (`sr-only`) for an accessible name.
    - Keep the optimized-image `<img src={optimizedUrl}>` constrained (`max-h`/`max-w`) to fit the viewport without
      clipping at 375px; keep Prev/Next and Close as ≥44px Tap_Targets; keep the Download
      `<a href="/e/{slug}/photos/{uuid}/download">` unchanged; keep `ArrowLeft`/`ArrowRight` key navigation.
    - Let Radix own Escape/focus-trap/scroll-lock/focus-restore/`aria-modal` (avoid double-handling Escape).
    - Add touch swipe navigation (`onTouchStart`/`onTouchEnd` with a horizontal threshold) for prev/next.
    - Props unchanged: `{ photos, index, slug, onIndexChange, onClose }`.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 2.5, 12.5, 12.6, 12.7_

  - [x] 2.2 [MODIFY] Polish the guest `PhotoUploader`
    - In `resources/js/components/PhotoUploader.tsx`, keep `useForm`, the hidden file input
      (`accept="image/jpeg,image/png,image/webp" multiple`), object-URL previews, the remove control, the error bag,
      the 429 `onHttpException` friendly rate-limit message, the inline success message, and
      `post(..., { forceFormData: true, preserveScroll: true })`.
    - Polish: make the Select control and per-preview remove `X` ≥44px Tap_Targets; disable the upload control and
      show `progress.percentage` while in flight; make the inline success clearer.
    - On failure, keep the pending selection intact and expose a retry affordance ("Try again" / re-enabled button)
      so the guest can resubmit without re-selecting.
    - OPTIONAL desktop enhancement: drag-and-drop (`dragover`/`drop` handlers appending to the same `photos` selection),
      progressive enhancement only.
    - Accessibility: `aria-busy`/live region for progress and success; accessible name on the remove control.
    - Accepted types and backend validation UNCHANGED.
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 10.4, 10.5, 12.9, 13.4_

  - [x] 2.3 [MODIFY] Polish the Public Event page
    - In `resources/js/pages/Public/Event.tsx`, replace the empty `aspect-video bg-muted` cover with a polished hero
      band (event name `h1`, uppercase eyebrow, friendly-formatted date, location); keep optional description rendering.
    - Add a Scan → Upload → Done progression (three numbered steps with icons/labels).
    - When `upload_enabled` is true, render a prominent upload CTA leading into `PhotoUploader`; when false, render the
      uploads-closed message and NO uploader controls.
    - Keep the full-width gallery link Button to `/e/{slug}/gallery` labeled with `photoCount`.
    - Mobile-first: single column, ≥16px body text, ≥44px tap targets, no horizontal overflow at 375px.
    - No prop, auth, or active-only changes (backend enforcement preserved).
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 2.1, 2.3, 2.4_

  - [x] 2.4 [MODIFY] Polish the Gallery page (skeletons + empty state)
    - In `resources/js/pages/Public/Gallery.tsx`, keep the existing state, `hasMore`, and `loadMore` via
      `router.get(..., { only: ['photos','pagination'], preserveState: true, preserveScroll: true })` with uuid dedupe
      on `onSuccess` and `onFinish` reset.
    - Add skeleton placeholders (`components/ui/skeleton`) in the grid region while `loading` is true on load-more.
    - Polish the empty state using the new `EmptyState` (icon + upload CTA linking back to the event page).
    - Keep `PhotoGrid`, broken-image fallback, only-ready enforcement, lazy loading, and the `PhotoViewer` mount.
    - Mobile spacing pass; no horizontal overflow at 375px.
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 2.1, 10.2, 10.3, 12.9_

- [x] 3. Organizer pages and forms
  - [x] 3.1 [MODIFY] Adopt the `Textarea` primitive in Create/Edit forms
    - In `resources/js/pages/Events/Create.tsx` and `resources/js/pages/Events/Edit.tsx`, replace the raw `<textarea>`
      (duplicated inline className) with the new `Textarea` primitive.
    - Keep `useForm`, `Heading`, `Label`, `Input`, `InputError`; Create posts `eventsStore()`, Edit does
      `put(eventsUpdate())` with its status select (leave the select as-is; OPTIONAL move to `ui/select`).
    - Visually mark required fields (name); keep `disabled={processing}` with a submit loading state to prevent
      duplicate submissions; success feedback comes from the backend flash (no client toast in the forms).
    - Usable inputs at ≥375px.
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 1.4_

  - [x] 3.2 [MODIFY] Redesign Events Index as a responsive card list
    - In `resources/js/pages/Events/Index.tsx`, replace the horizontally scrolling table with a responsive card list
      (stacked cards on mobile with no horizontal scroll at 375px; consistent card path on `sm+`).
    - Each card shows Name, Date, Location, status `Badge`, Uploads, Created, and Actions as ≥44px Tap_Targets.
    - Keep the delete confirmation `Dialog`, existing routes, and `Badge` usage.
    - Add an empty state via `EmptyState` with a create-event CTA.
    - _Requirements: 2.2, 2.1, 2.3, 10.3_

  - [x] 3.3 [MODIFY] Replace stale placeholders on Event Show with real links
    - In `resources/js/pages/Events/Show.tsx`, remove the `comingSoonSections` ("Photo Gallery", "Uploads")
      placeholder cards.
    - Add real Tap_Target links to the public event page (`publicUrl`) and the Gallery (`/e/{slug}/gallery`).
    - Keep the Event Details card, edit/delete controls, and the delete `Dialog`; give the QR card header
      event-title context.
    - Do NOT add a photo count on Show — R15.4 is explicitly deferred per the design (the `Event` prop carries no
      count and no backend query is permitted).
    - Stack cards; no horizontal overflow at 375px.
    - _Requirements: 15.1, 15.2, 15.3, 9.1, 2.1_

  - [x] 3.4 [MODIFY] Polish the organizer Dashboard
    - In `resources/js/pages/dashboard.tsx`, keep the 3 stat cards, Recent Events list, and Create Event link.
    - Improve scannability (spacing/typography); render a status `Badge` per recent event using the existing primitive.
    - Add an empty state via `EmptyState` with a create-event CTA.
    - Do NOT add a photo count (`recentEvents` carries none; no backend change); no new business functionality.
    - _Requirements: 7.1, 7.2, 7.4, 7.5, 7.6, 7.7, 10.3_

- [x] 4. QR presentation and auth consistency
  - [x] 4.1 [MODIFY] Polish the QR presentation on Event Show
    - Ensure the Events/Show QR card shows event-title context and the `publicUrl` as text with no horizontal overflow
      at 375px; `QrCode.tsx` internals stay unchanged (keeps its direct copy/open/download toasts and download behavior).
    - OPTIONAL print-friendly utility/stylesheet for the QR code.
    - If the QR card header context was already handled in task 3.3, fold this in and note it; otherwise complete it here.
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6_

  - [x] 4.2 [MODIFY] Auth pages consistency pass
    - In `resources/js/pages/auth/*`, do a consistency pass ONLY using the existing `AuthLayout` and primitives:
      confirm validation errors render, the submit shows a loading/disabled state while processing, and inputs are
      usable at ≥375px.
    - No authentication behavior change.
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

- [x] 5. Cross-cutting accessibility and performance sweep
  - [x] 5.1 [MODIFY] Accessibility + performance sweep over touched surfaces
    - Verify and make small fixes across the files touched in waves 1–3 (no new files): semantic HTML, form labels,
      accessible control names, image alt text, keyboard operability with visible focus, modal focus/Escape (from Radix),
      sufficient contrast, and `aria-busy`/live regions for loading.
    - Confirm performance behaviors are preserved: lazy images (`PhotoCard`), thumbnail/optimized variants used (not
      originals), pagination/load-more preserved, duplicate-submit prevention, and no redundant Inertia requests.
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.9, 13.1, 13.2, 13.3, 13.4, 13.5, 13.6_

- [x] 6. Final verification
  - [x] 6.1 [VERIFY] Run automated gates and the manual UI matrix
    - Run `npx tsc --noEmit` (0 errors), `npm run build` (clean), and the FULL `php artisan test` (195 green — confirm
      the toast touch did not break `EventTest`/`EventValidationTest`).
    - Perform the manual UI matrix at 375/390/412px + desktop Chrome per the design's manual checklist: public event page,
      uploader, gallery + skeletons, viewer focus/scroll-lock/swipe, dashboard, Events Index cards, Show links,
      forms + success toast, one-toast-per-action, auth pages.
    - Document results. If `tsc`/`build`/`test` fails, fix minimally (frontend only; do not alter the backend beyond the
      task 1.3 toast touch).
    - _Requirements: 16.1, 16.2, 16.3, 16.4 (and manual coverage of R2, R3, R4, R5, R6, R7, R8, R9, R11, R12)_

## Notes

- Every task references specific requirement sub-clauses for traceability.
- Tasks are marked `[NEW]` (create a new file), `[MODIFY]` (edit an existing file), or `[VERIFY]` (verification pass).
- Task 1.3 is the single allowed, additive-only backend change; all other tasks are frontend.
- Optional enhancements (desktop drag-and-drop, print-friendly QR, `ui/select` in Edit) are called out as OPTIONAL
  within their tasks; no task is marked as skippable.
- Foundation components (Textarea, EmptyState) and the backend toast land before the pages that consume them.
- Regression is guarded by `php artisan test` + `npx tsc --noEmit` + `npm run build`; no JS test framework is introduced.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3", "2.4"] },
    { "id": 2, "tasks": ["3.1", "3.2", "3.3", "3.4"] },
    { "id": 3, "tasks": ["4.1", "4.2"] },
    { "id": 4, "tasks": ["5.1"] },
    { "id": 5, "tasks": ["6.1"] }
  ]
}
```
