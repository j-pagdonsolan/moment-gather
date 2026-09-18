# Requirements Document

## Introduction

MomentGather Phase 11 is a **UI/UX polish** effort for an existing, working event photo-sharing application (Laravel 13 + React 19 + Inertia v3 + TypeScript + Tailwind v4, running local-only). Phases 1–10 are complete with 195 passing backend tests. This phase adds **no new business features** and introduces **no changes to the database, queue, security, rate limiting, routes, or cloud/Redis/S3/payments/AI**. The goal is to make the existing experience feel finished, consistent, and mobile-first.

This is **polish, not rebuild**. The application already has a mature foundation of shadcn/ui components (in `resources/js/components/ui`), Tailwind v4, lucide-react icons, `qrcode.react`, and a working `sonner`-based flash-toast system. Requirements reuse these primitives and only introduce small, reusable components where duplication is significant (for example, a `Textarea` primitive to replace a duplicated raw `<textarea>`).

Because the product is primarily a **guest-facing mobile experience** (attendees scanning a QR code at an event and uploading photos from a phone), **mobile-first responsiveness is the top priority**. Guest-facing surfaces (public event page, uploader, gallery, photo viewer) take precedence over organizer surfaces.

**One allowed additive backend touch.** The only backend change permitted in this phase is wiring event-CRUD success feedback through the **existing** flash-toast system by adding `Inertia::flash('toast', ['type' => 'success', 'message' => ...])` to `EventController::store()`, `update()`, and `destroy()` immediately before their existing redirect returns. This is a UI-integration change only. It MUST NOT alter validation, authorization, security headers, rate limiting, routes, or any behavior, and all Phase 10 backend tests MUST continue to pass. This pattern already exists in `ProfileController` and `SecurityController`.

**Frontend/JS test framework is out of scope.** No Vitest, Jest, or other JS test framework is introduced (same posture as Phase 10). Regression is guarded entirely by the existing Phase 10 backend suite run via `php artisan test`, plus a clean `npx tsc --noEmit` and `npm run build`.

**Phases 1–10 functionality MUST be preserved.** No working behavior may change except the additive toast flashes described above.

## Glossary

- **Guest**: An unauthenticated visitor who reaches a public event page (typically via QR code on a mobile device) to upload or view photos. Has no organizer account and no sidebar layout.
- **Organizer**: An authenticated user who creates and manages events through the organizer dashboard and sidebar-based application layout.
- **Public_Event_Page**: The no-auth, active-only page at `pages/Public/Event.tsx` (route `public.events.show`) that guests land on to see event info and upload photos.
- **Upload_Flow**: The guest photo-upload interaction implemented by `components/PhotoUploader.tsx`: select → preview → remove → upload → progress → success/failure → retry.
- **Gallery**: The public photo gallery at `pages/Public/Gallery.tsx` composed of `PhotoGrid` and `PhotoCard`, with load-more pagination.
- **Photo_Viewer**: The full-screen photo display component `components/PhotoViewer.tsx` (currently a hand-rolled overlay) providing close, previous/next, and download.
- **Flash_Toast**: The existing toast notification system: `Inertia::flash('toast', {type, message})` on the backend is read by `useFlashToast()` (`hooks/use-flash-toast.ts`) via `router.on('flash')` and displayed through `sonner` (`components/ui/sonner.tsx`, mounted in `app.tsx`). Type defined in `types/ui.ts` as `FlashToast`.
- **Design_System_Primitive**: A reusable UI building block in `resources/js/components/ui` (shadcn/ui) such as `button`, `input`, `card`, `badge`, `dialog`, `alert`, `skeleton`, `spinner`, `sonner`.
- **Mobile_First**: A design approach where layouts, tap targets, and content are designed for small screens first and progressively enhanced for larger viewports using Tailwind responsive utilities.
- **Tap_Target**: An interactive element (button, link, control) sized for reliable touch interaction, with a minimum touch area of approximately 44x44 CSS pixels.
- **Empty_State**: The UI shown when a collection (events, photos) has no items, guiding the user toward the next action.
- **Loading_State**: The UI shown while data is being fetched or an action is processing (Inertia progress bar, skeletons, spinners, disabled buttons).
- **Ready_Photo_Count**: The number of successfully processed photos available for an event (already computed by the backend).
- **Coming_Soon_Placeholder**: The stale placeholder cards on `Events/Show.tsx` (`comingSoonSections` for "Photo Gallery" and "Uploads") that reference features which now exist and MUST be replaced with real links.

## Out of Scope

The following are explicitly excluded from Phase 11:

- New business features or capabilities of any kind
- Database schema, migration, model, or query changes
- Queue, background-job, or processing-pipeline changes
- Security, authorization, authentication behavior, security-header, or rate-limiting changes
- Route additions, removals, or changes
- Cloud or external infrastructure (Redis, S3, CDN, external storage)
- Payments, billing, or monetization
- AI or machine-learning features
- Deployment, CI/CD, or production-environment changes
- Introducing a JavaScript/frontend test framework (Vitest, Jest, Playwright, etc.)
- Major refactor or redesign of the working architecture, data flow, or component structure
- Any backend change other than the single allowed additive `Inertia::flash('toast', ...)` in `EventController::store/update/destroy`

## What Already Exists (Keep) vs What to Polish (New)

| Area | Already Exists (Keep) | Polish in Phase 11 (New) |
|---|---|---|
| Design primitives | shadcn/ui set (button, input, card, badge, dialog, alert, skeleton, spinner, sonner, etc.), Tailwind v4, lucide-react | Consistent application of tokens; add `Textarea` primitive; optional reusable `EmptyState` / `StatusBadge` where duplication is significant |
| Toast system | `useFlashToast` + `sonner` + `FlashToast` type; Settings controllers flash toast; `QrCode` uses `toast` directly | Add event created/updated/deleted success toasts via the allowed backend touch; standardize usage; avoid over-notifying |
| Public event page | No-auth active-only page, event info, uploader/closed text, gallery button, empty cover placeholder | Prominent SCAN→UPLOAD→DONE flow, clearer info hierarchy, prominent upload CTA, mobile layout |
| Guest uploader | Select/preview/remove/upload/progress, error bag, 429 message, inline success | Clearer states, tap targets, optional desktop drag-and-drop, improved validation/progress/retry feedback |
| Gallery | Responsive grid, lazy `loading="lazy"`, load-more dedupe, broken-image fallback, only-ready photos | Add skeleton/loading states, polish empty state, mobile spacing |
| Photo viewer | Hand-rolled overlay, close/prev/next, download endpoint, Escape + arrow keys | Focus trap, `role="dialog"`/`aria-modal`, body-scroll-lock, focus restore, mobile swipe/large tap targets |
| Dashboard | Stat cards, recent events, create link | Scannability, status badges, photo count where available, empty/loading polish, success/error toasts |
| Events index | Header, create button, table in `overflow-x-auto`, delete dialog | Responsive card layout on mobile (no horizontal scroll), tap targets |
| Event Show | Details card, QR card, delete/edit, `comingSoonSections` placeholders | Replace stale "Coming Soon" cards with real gallery/public-page links + ready photo count |
| Create/Edit forms | `useForm`, Label + Input + InputError, raw `<textarea>`, `disabled={processing}` | Shared `Textarea` primitive, clearer required fields, success toast, duplicate-submit prevention, mobile |
| QR experience | `QrCode` component, `publicUrl`, download/copy/open, direct toasts | Event title context, print-friendly if practical, mobile responsiveness |
| Auth pages | Login/register/password styling, validation, `auth-layout` | Consistent styling, loading states, mobile (no behavior change) |
| Regression guard | 195 passing backend tests (`php artisan test`) | Keep green; `npx tsc --noEmit` + `npm run build` clean |

## Requirements

### Requirement 1: Consistent UI Design System

**User Story:** As a Guest or Organizer, I want a visually consistent interface, so that the application feels polished and trustworthy.

#### Acceptance Criteria

1. THE Application SHALL apply consistent typography, spacing, and color tokens across all pages using the existing Tailwind v4 configuration and shadcn/ui primitives.
2. WHERE a UI element renders a button, input, label, form error, card, badge, modal, alert, or loading indicator, THE Application SHALL use the corresponding Design_System_Primitive from `resources/js/components/ui`.
3. WHERE the same UI markup is duplicated across two or more pages, THE Application SHALL extract a single reusable component in `resources/js/components/ui` rather than duplicate markup.
4. THE Application SHALL provide a `Textarea` Design_System_Primitive and use it in place of the raw `<textarea>` currently duplicated in the event create and edit forms.
5. WHILE styling any surface, THE Application SHALL NOT introduce a new UI component library or CSS framework beyond the existing Tailwind v4 and shadcn/ui foundation.

### Requirement 2: Mobile-First Responsiveness

**User Story:** As a Guest using a phone at an event, I want every screen to work on a small display, so that I can view and upload photos without layout problems.

#### Acceptance Criteria

1. WHILE a viewport width is 375 CSS pixels or greater, THE Application SHALL render every page without horizontal overflow of the page body.
2. WHILE a viewport width is 375 CSS pixels or greater, THE Events_Index SHALL present event records as a responsive card layout instead of a horizontally scrolling table.
3. THE Application SHALL render every interactive Tap_Target with a touch area of at least 44 by 44 CSS pixels on viewport widths of 375 CSS pixels or greater.
4. WHILE a viewport width is 375 CSS pixels or greater, THE Application SHALL render body text at a size of at least 16 CSS pixels for guest-facing content.
5. WHILE a dialog or Photo_Viewer is open on a viewport width of 375 CSS pixels or greater, THE Application SHALL fit the dialog content within the viewport without clipping controls.
6. WHILE a viewport width is 375 CSS pixels or greater, THE Application SHALL display images scaled to fit their container without overflowing the container bounds.

### Requirement 3: Public Event Page Polish

**User Story:** As a Guest who scanned an event QR code, I want a clear page that guides me to upload and view photos, so that I can participate without confusion.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL display the event name, friendly-formatted event date, and location.
2. WHERE the event has a description, THE Public_Event_Page SHALL display the description.
3. THE Public_Event_Page SHALL present a Scan-Upload-Done progression that visually communicates the guest flow.
4. WHILE the event has `upload_enabled` set to true, THE Public_Event_Page SHALL display a prominent upload call-to-action.
5. IF the event has `upload_enabled` set to false, THEN THE Public_Event_Page SHALL display an uploads-closed message and SHALL NOT display the Upload_Flow controls.
6. THE Public_Event_Page SHALL provide a Tap_Target that navigates to the Gallery and SHALL display the Ready_Photo_Count.
7. THE Public_Event_Page SHALL remain accessible without authentication and SHALL render only for active events, preserving existing backend enforcement.

### Requirement 4: Guest Upload Experience

**User Story:** As a Guest, I want a clear photo-upload flow with feedback, so that I know my photos were received or why they failed.

#### Acceptance Criteria

1. WHEN a Guest selects one or more image files, THE Upload_Flow SHALL display a preview thumbnail for each selected file.
2. WHEN a Guest activates the remove control on a preview, THE Upload_Flow SHALL remove that file from the pending selection.
3. WHILE an upload is in progress, THE Upload_Flow SHALL disable the upload control and display upload progress as a percentage.
4. WHEN an upload completes successfully, THE Upload_Flow SHALL display an inline success message.
5. IF the server returns validation errors, THEN THE Upload_Flow SHALL display the returned error messages as readable text.
6. IF the server returns an HTTP 429 rate-limit response, THEN THE Upload_Flow SHALL display a friendly rate-limit message.
7. WHEN an upload fails, THE Upload_Flow SHALL allow the Guest to retry the upload.
8. WHERE the client environment is a desktop browser, THE Upload_Flow MAY accept files via drag-and-drop in addition to the file picker.
9. THE Upload_Flow SHALL restrict file selection to the existing accepted image types (JPEG, PNG, WebP) and SHALL NOT change backend upload validation or security.

### Requirement 5: Gallery Experience

**User Story:** As a Guest, I want to browse event photos smoothly, so that I can enjoy the shared moments.

#### Acceptance Criteria

1. THE Gallery SHALL display photos in a responsive grid that adapts column count to viewport width.
2. WHILE photos are loading, THE Gallery SHALL display skeleton placeholders for the loading region.
3. IF the event has no photos, THEN THE Gallery SHALL display a polished Empty_State with a Tap_Target to upload.
4. WHEN a photo thumbnail fails to load, THE Gallery SHALL display the existing broken-image fallback.
5. WHEN a Guest activates the load-more control, THE Gallery SHALL append additional photos while preventing duplicate entries by photo identifier.
6. THE Gallery SHALL display only successfully processed photos, preserving existing backend enforcement.
7. THE Gallery SHALL render each photo thumbnail with lazy loading.

### Requirement 6: Photo Viewer

**User Story:** As a Guest, I want to view a photo full-screen with easy navigation and download, so that I can focus on and save individual moments.

#### Acceptance Criteria

1. WHEN a Guest activates a photo in the Gallery, THE Photo_Viewer SHALL display the optimized image in a full-screen overlay.
2. THE Photo_Viewer SHALL provide close, previous, and next controls as Tap_Targets.
3. WHEN a Guest activates the download control, THE Photo_Viewer SHALL initiate a download using the existing download endpoint.
4. WHERE the client environment is a desktop browser, THE Photo_Viewer SHALL support keyboard navigation using Escape to close and Left and Right arrows to navigate.
5. WHILE the Photo_Viewer is open, THE Photo_Viewer SHALL expose a dialog role and modal semantics to assistive technology.
6. WHILE the Photo_Viewer is open, THE Photo_Viewer SHALL trap keyboard focus within the viewer and SHALL prevent scrolling of the underlying page.
7. WHEN the Photo_Viewer closes, THE Photo_Viewer SHALL restore keyboard focus to the element that opened it.
8. WHERE the client environment is a touch device, THE Photo_Viewer SHALL support navigation via swipe gestures or via Tap_Targets sized for touch.

### Requirement 7: Organizer Dashboard Polish

**User Story:** As an Organizer, I want a scannable dashboard, so that I can quickly assess and manage my events.

#### Acceptance Criteria

1. THE Dashboard SHALL display event summary statistics and a list of recent events.
2. THE Dashboard SHALL display a status badge for each listed event using a consistent Design_System_Primitive.
3. WHERE the Ready_Photo_Count is available for a listed event, THE Dashboard SHALL display the Ready_Photo_Count.
4. THE Dashboard SHALL provide a Tap_Target to create a new event.
5. IF the Organizer has no events, THEN THE Dashboard SHALL display a polished Empty_State with a create-event Tap_Target.
6. WHILE dashboard data is loading, THE Dashboard SHALL display a Loading_State.
7. THE Dashboard SHALL NOT introduce any new business functionality.

### Requirement 8: Event Create and Edit Forms

**User Story:** As an Organizer, I want clear event forms with obvious feedback, so that I can create and edit events confidently.

#### Acceptance Criteria

1. THE Event_Form SHALL display a visible label for each input field using the existing Label primitive.
2. THE Event_Form SHALL visually indicate which fields are required.
3. IF a field fails validation, THEN THE Event_Form SHALL display the field-level error using the existing InputError component.
4. WHILE a form submission is in progress, THE Event_Form SHALL display a submit Loading_State and SHALL disable the submit control to prevent duplicate submissions.
5. WHEN an event is created successfully, THE Application SHALL display a success Flash_Toast.
6. WHEN an event is updated successfully, THE Application SHALL display a success Flash_Toast.
7. THE Event_Form SHALL render usable inputs on viewport widths of 375 CSS pixels or greater.

### Requirement 9: QR Experience Polish

**User Story:** As an Organizer, I want a clear QR page for my event, so that I can share and display the event link.

#### Acceptance Criteria

1. THE QR_Experience SHALL display the event title as context alongside the QR code.
2. THE QR_Experience SHALL display the public event URL as text.
3. WHEN an Organizer activates the download control, THE QR_Experience SHALL download the QR code image using the existing behavior.
4. WHEN an Organizer activates the copy-link or open-link control, THE QR_Experience SHALL perform the existing copy or open behavior and SHALL provide toast confirmation.
5. WHERE printing is practical, THE QR_Experience MAY provide a print-friendly presentation of the QR code.
6. THE QR_Experience SHALL render without horizontal overflow on viewport widths of 375 CSS pixels or greater.

### Requirement 10: Loading, Empty, Success, and Error States

**User Story:** As any user, I want clear feedback for loading, empty, success, and error conditions, so that I always understand the application state.

#### Acceptance Criteria

1. WHILE an Inertia page navigation is in progress, THE Application SHALL display a page-level progress indicator.
2. WHILE the Gallery is fetching photos, THE Application SHALL display a Loading_State.
3. WHERE a collection has no items, THE Application SHALL display an Empty_State that guides the user to the next action.
4. WHEN a create, update, upload, or status-change action succeeds, THE Application SHALL display success feedback appropriate to that surface.
5. IF an action fails due to validation, upload failure, processing failure, unauthorized access, an inactive event, or a failed download, THEN THE Application SHALL display a readable error message.
6. IF an error is displayed to a user, THEN THE Application SHALL exclude stack traces, SQL, filesystem paths, and internal identifiers from the displayed message.

### Requirement 11: Notifications and Toasts

**User Story:** As an Organizer, I want consistent notifications for my actions, so that I get confirmation without being overwhelmed.

#### Acceptance Criteria

1. THE Application SHALL use the existing Flash_Toast system as the single mechanism for organizer action notifications.
2. WHEN an event is created, updated, or deleted, THE Application SHALL emit a success Flash_Toast via `Inertia::flash('toast', ['type' => 'success', 'message' => ...])` added to `EventController::store`, `update`, and `destroy` immediately before their existing redirects.
3. THE Application SHALL preserve the existing inline success feedback for guest photo uploads and SHALL NOT require organizer Flash_Toast for guest uploads.
4. THE Application SHALL NOT emit more than one Flash_Toast per completed organizer action.
5. THE backend Flash_Toast additions SHALL NOT change validation, authorization, security, rate limiting, routes, or any other behavior, and all Phase 10 backend tests SHALL continue to pass.

### Requirement 12: Accessibility

**User Story:** As a user relying on assistive technology or keyboard navigation, I want an accessible interface, so that I can use the application effectively.

#### Acceptance Criteria

1. THE Application SHALL use semantic HTML elements for structural and interactive content.
2. THE Application SHALL associate a text label with every form input.
3. THE Application SHALL provide an accessible name for every interactive control.
4. THE Application SHALL provide descriptive alternative text for content images.
5. THE Application SHALL support keyboard operation of all interactive controls and SHALL render a visible focus indicator on the focused control.
6. WHILE a modal is open, THE Application SHALL manage focus within the modal and SHALL close the modal when the Escape key is pressed.
7. WHERE native HTML semantics are insufficient, THE Application SHALL apply ARIA attributes to convey role, state, and properties.
8. THE Application SHALL render text with contrast sufficient to meet a 4.5-to-1 ratio for normal-size text.
9. WHILE an asynchronous action is in progress, THE Application SHALL communicate the Loading_State to assistive technology.

### Requirement 13: Performance-Oriented UI

**User Story:** As a Guest on a mobile connection, I want the interface to load efficiently, so that browsing and uploading feel responsive.

#### Acceptance Criteria

1. THE Application SHALL render photo thumbnails using lazy loading.
2. THE Application SHALL display the existing generated thumbnail and optimized image variants rather than full-resolution originals for gallery and viewer display.
3. THE Application SHALL preserve the existing pagination and load-more behavior for the Gallery.
4. THE Application SHALL prevent duplicate submissions by disabling submit controls while a request is in flight.
5. THE Application SHALL avoid issuing redundant Inertia requests for data already loaded.
6. THE Application SHALL NOT introduce new caching layers or infrastructure.

### Requirement 14: Authentication UI Consistency

**User Story:** As an Organizer, I want consistent authentication pages, so that signing in and managing my account feels cohesive.

#### Acceptance Criteria

1. THE Authentication_Pages SHALL apply styling consistent with the rest of the application using the existing auth-layout and Design_System_Primitives.
2. IF an authentication field fails validation, THEN THE Authentication_Pages SHALL display the validation error.
3. WHILE an authentication form submission is in progress, THE Authentication_Pages SHALL display a submit Loading_State.
4. THE Authentication_Pages SHALL render usable inputs on viewport widths of 375 CSS pixels or greater.
5. THE Authentication_Pages SHALL NOT change any authentication behavior.

### Requirement 15: Replace Stale Placeholders on Event Show

**User Story:** As an Organizer, I want the event detail page to link to real features, so that I can access the gallery and public page directly.

#### Acceptance Criteria

1. THE Event_Show_Page SHALL remove the Coming_Soon_Placeholder cards for "Photo Gallery" and "Uploads".
2. THE Event_Show_Page SHALL provide a Tap_Target that navigates to the public event page.
3. THE Event_Show_Page SHALL provide a Tap_Target that navigates to the Gallery.
4. THE Event_Show_Page SHALL display the Ready_Photo_Count for the event.

### Requirement 16: Regression Preservation

**User Story:** As a maintainer, I want confidence that polish did not break existing behavior, so that Phases 1–10 remain intact.

#### Acceptance Criteria

1. WHEN the backend test suite is run via `php artisan test`, THE Application SHALL pass all existing Phase 10 tests.
2. WHEN TypeScript type checking is run via `npx tsc --noEmit`, THE Application SHALL report no type errors.
3. WHEN the frontend build is run via `npm run build`, THE Application SHALL complete without build errors.
4. THE Application SHALL preserve all Phase 1 through Phase 10 functionality except the additive Flash_Toast emissions in `EventController::store`, `update`, and `destroy`.
