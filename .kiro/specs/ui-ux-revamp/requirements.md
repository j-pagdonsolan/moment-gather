# Requirements Document

## Introduction

Phase 14 of MomentGather is a comprehensive UI/UX revamp that elevates the product
from a functional prototype to a polished, production-ready application. The revamp
covers every surface a user can see: the marketing landing page, authentication flows,
the public event and photo gallery pages, the organizer dashboard and event management
screens, the billing settings page, and the admin panel.

The tech stack (Laravel + React + TypeScript + Inertia.js + Tailwind CSS v4 + shadcn/ui)
is unchanged. The existing backend, models, controllers, routes, billing logic, and auth
system are out of scope — only the frontend presentation layer is modified.

---

## Glossary

- **Landing_Page**: The public marketing page at `/` (`pages/welcome.tsx`). Currently shows the default Laravel starter; needs full replacement.
- **Auth_Pages**: The six authentication screens: login, register, forgot-password, reset-password, verify-email, confirm-password (`pages/auth/`).
- **Public_Event_Page**: The guest-facing event page at `/e/{slug}` (`pages/Public/Event.tsx`).
- **Gallery_Page**: The public photo gallery at `/e/{slug}/gallery` (`pages/Public/Gallery.tsx`).
- **Organizer_Dashboard**: The authenticated organizer dashboard at `/dashboard` (`pages/dashboard.tsx`).
- **Event_Pages**: The authenticated event management pages (`pages/Events/Index.tsx`, `Create.tsx`, `Edit.tsx`, `Show.tsx`).
- **Billing_Page**: The billing settings page at `/settings/billing` (`pages/settings/billing.tsx`).
- **Admin_Pages**: All pages under `pages/Admin/` (Dashboard, Users, Events, Photos, Plans, Subscriptions, Payments, AuditLogs).
- **Design_System**: The shared set of CSS custom properties, typography scale, Tailwind utilities, and reusable React components that enforce visual consistency.
- **PhotoUploader**: The `components/PhotoUploader.tsx` component used on the Public_Event_Page.
- **PhotoGrid**: The `components/PhotoGrid.tsx` component used on the Gallery_Page.
- **PhotoViewer**: The `components/PhotoViewer.tsx` lightbox component used on the Gallery_Page.
- **Skeleton**: The `components/ui/skeleton.tsx` shadcn component used for loading placeholders.
- **EmptyState**: The `components/empty-state.tsx` component used when a collection has no items.
- **Stat_Card**: A Card component displaying a single numeric metric with a label and icon.
- **PlanCard**: The `components/billing/PlanCard.tsx` billing plan display component.
- **UsageMeter**: The `components/billing/UsageMeter.tsx` progress-bar usage component.
- **Brand_Color**: A distinctive oklch accent color (violet/indigo) added to `app.css` to differentiate MomentGather from the default neutral shadcn palette.

---

## Requirements

### Requirement 1 — Design System Foundation (14A/shared)

**User Story:** As a developer, I want a coherent design system with a defined brand color, typography scale, and reusable tokens, so that all pages share a consistent visual identity.

#### Acceptance Criteria

1. THE Design_System SHALL define a `--brand` oklch custom property in `app.css` that serves as the primary brand color (violet/indigo range) for both light and dark modes.
2. THE Design_System SHALL expose a `--brand-foreground` custom property ensuring WCAG AA contrast against `--brand` backgrounds.
3. THE Design_System SHALL map `--color-brand` and `--color-brand-foreground` in the `@theme` block so the brand color is accessible as a Tailwind utility class.
4. THE Design_System SHALL define a typography scale with at least four named text sizes (xs, sm, base, lg, xl, 2xl) used consistently across all pages.
5. WHEN a new reusable component is needed across two or more pages, THE Design_System SHALL provide it as a shared file under `resources/js/components/` rather than duplicating markup.

---

### Requirement 2 — Marketing Landing Page (14A)

**User Story:** As a prospective customer, I want a compelling marketing landing page, so that I understand what MomentGather does and am motivated to sign up.

#### Acceptance Criteria

1. WHEN a visitor arrives at `/`, THE Landing_Page SHALL display a navigation bar with the MomentGather logo/wordmark, navigation links (Features, Pricing, FAQ), a Login link, and a prominent "Get Started" CTA button.
2. THE Landing_Page SHALL display a hero section with the headline "Everyone captures the moment. We gather them.", a sub-headline explaining the product value, and a primary CTA button linking to the registration page.
3. THE Landing_Page SHALL display a "How It Works" section with exactly four numbered steps illustrating the guest photo-sharing flow.
4. THE Landing_Page SHALL display a Features section listing at least four key product differentiators, each with an icon, title, and description.
5. THE Landing_Page SHALL display a Pricing section showing at minimum the Free and Pro plans with their prices and feature lists, sourced from the plan data passed as a page prop.
6. THE Landing_Page SHALL display an FAQ section with at least five question/answer pairs relevant to MomentGather.
7. THE Landing_Page SHALL display a final CTA section and a footer with copyright notice and navigation links.
8. WHEN a logged-in user visits `/`, THE Landing_Page SHALL display a "Go to Dashboard" button instead of "Get Started" / "Log in".
9. THE Landing_Page SHALL render correctly at mobile (≥ 375 px), tablet (≥ 768 px), and desktop (≥ 1280 px) viewport widths.
10. THE Landing_Page SHALL use the `null` layout (no authenticated sidebar) as it already does in `app.tsx`.

---

### Requirement 3 — Auth Pages (14B)

**User Story:** As a user, I want a clean, trustworthy authentication experience, so that I feel confident entering my credentials.

#### Acceptance Criteria

1. THE Auth_Pages SHALL display the MomentGather brand logo above the form on every auth screen.
2. WHEN a form is submitting, THE Auth_Pages SHALL disable the submit button and show a loading spinner (the existing `Spinner` component).
3. THE Auth_Pages SHALL display field-level validation errors directly below each input immediately after a failed submission.
4. THE Auth_Pages SHALL maintain the existing `AuthLayout` → `AuthSimpleLayout` wrapping so no routing or middleware changes are needed.
5. THE Auth_Pages SHALL render the login and register forms with clear label, input, and helper-text spacing following an 8px grid.
6. WHEN the register page is displayed, THE Auth_Pages SHALL show a password strength indicator below the password field.

---

### Requirement 4 — Public Event Page (14C)

**User Story:** As a guest at an event, I want an intuitive, welcoming upload page, so that I can share my photos quickly without confusion.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL display a full-width hero header with event name, optional date, and optional location in a visually distinct band using the `--brand` color gradient.
2. THE Public_Event_Page SHALL display the "Scan → Upload → Done" three-step flow as a horizontal stepper with numbered circles and connecting lines.
3. WHEN the upload zone is in the idle state, THE PhotoUploader SHALL display a drag-and-drop zone with a dashed border, upload icon, and instructional copy ("Drag photos here or click to browse").
4. WHEN files are dragged over the upload zone, THE PhotoUploader SHALL apply a highlighted border using `--ring` or `--brand` color and a background tint to indicate the active drop target.
5. WHEN photos are selected, THE PhotoUploader SHALL display a grid of image previews, each with a remove button accessible by keyboard.
6. WHEN an upload is in progress, THE PhotoUploader SHALL display a progress bar showing the percentage completion.
7. WHEN the upload succeeds, THE PhotoUploader SHALL display a success state with a checkmark icon and a "View Gallery" link.
8. IF the upload fails with a server validation error, THEN THE PhotoUploader SHALL display the error messages and keep the current photo selection intact for retry.
9. IF the upload fails with an HTTP 429 rate-limit response, THEN THE PhotoUploader SHALL display a friendly rate-limit message.
10. WHEN the upload_enabled flag is false, THE Public_Event_Page SHALL display a polite "Photo uploads are currently closed" notice in place of the uploader.
11. THE Public_Event_Page SHALL use the `null` layout (no authenticated sidebar) as it already does in `app.tsx`.

---

### Requirement 5 — Photo Gallery (14D)

**User Story:** As a guest, I want a beautiful photo gallery with smooth browsing, so that I can enjoy and download the event photos.

#### Acceptance Criteria

1. THE Gallery_Page SHALL display photos in a responsive masonry or uniform-grid layout with at least two columns on mobile and four columns on desktop.
2. WHEN photos are loading (initial page load or "Load More"), THE Gallery_Page SHALL display Skeleton placeholders matching the expected photo grid layout.
3. WHEN the gallery is empty, THE Gallery_Page SHALL display the EmptyState component with an icon, a "No photos yet" message, and a link back to the upload page.
4. WHEN a user clicks a photo thumbnail, THE Gallery_Page SHALL open the PhotoViewer lightbox showing the full-size image.
5. WHEN the PhotoViewer is open, THE Gallery_Page SHALL support keyboard navigation (ArrowLeft, ArrowRight to move between photos; Escape to close).
6. WHEN the PhotoViewer is open, THE Gallery_Page SHALL provide a download button for the currently displayed photo.
7. THE Gallery_Page SHALL display a sticky or persistent header with the event name and a link back to the upload page.
8. THE Gallery_Page SHALL use the `null` layout (no authenticated sidebar) as it already does in `app.tsx`.

---

### Requirement 6 — Organizer Dashboard (14E)

**User Story:** As an event organizer, I want an informative dashboard, so that I can quickly understand the state of my events and take action.

#### Acceptance Criteria

1. THE Organizer_Dashboard SHALL display stat cards for Total Events, Active Events, and Archived Events, each with a relevant icon and a large numeric value.
2. THE Organizer_Dashboard SHALL display a "Create Event" button in a consistent, prominent position at the top of the page.
3. WHEN the recent events list is empty, THE Organizer_Dashboard SHALL display the EmptyState component with a create-event CTA.
4. WHEN the recent events list has items, THE Organizer_Dashboard SHALL display each event with its name, status badge (color-coded active/archived), event date, and a link to its show page.
5. THE Organizer_Dashboard SHALL use the existing `AppLayout` sidebar layout with breadcrumbs as currently implemented.

---

### Requirement 7 — Event Management Pages (14E continued)

**User Story:** As an event organizer, I want clear, consistent event management pages, so that I can create, view, edit, and delete events efficiently.

#### Acceptance Criteria

1. THE Event_Pages SHALL display event cards in a responsive grid (1 column mobile, 2 tablet, 3 desktop) on the index page.
2. WHEN an event card is displayed, THE Event_Pages SHALL show the event name, status badge, event date, location, and upload status.
3. THE Event_Pages CREATE and EDIT forms SHALL use consistent field spacing, labels positioned above inputs, and inline validation error messages.
4. THE Event_Pages SHOW page SHALL display the QR code, public URL, event details, and action buttons in a clearly structured layout.
5. WHEN a user triggers the delete action, THE Event_Pages SHALL show a confirmation dialog before proceeding with deletion.

---

### Requirement 8 — Billing Settings Page (14F)

**User Story:** As a subscriber, I want a clear billing page, so that I can understand my current plan, usage, and subscription status.

#### Acceptance Criteria

1. THE Billing_Page SHALL display the user's current plan name and a colored badge (Pro vs. Free) at the top of the section.
2. THE Billing_Page SHALL display UsageMeter bars for Active Events, Photos, and Storage with used/limit values.
3. WHEN the user is on the Free plan, THE Billing_Page SHALL display an upgrade CTA card alongside the current plan card.
4. WHEN the user is on the Pro plan with an active subscription, THE Billing_Page SHALL display a "Cancel subscription" button.
5. WHEN the subscription is scheduled for cancellation, THE Billing_Page SHALL display the grace-period end date and suppress the cancel button.
6. THE Billing_Page SHALL display a payment history list showing date, amount, and status for past payments.
7. THE Billing_Page SHALL use the existing `AppLayout` + `SettingsLayout` wrapping as currently implemented.

---

### Requirement 9 — Admin UI (14G)

**User Story:** As a super-admin, I want a well-organized admin panel, so that I can efficiently monitor platform health and manage users and content.

#### Acceptance Criteria

1. THE Admin_Pages SHALL replace the repetitive per-card layout in `Admin/Dashboard.tsx` with a reusable Stat_Card component that accepts `label`, `value`, and `icon` props.
2. THE Admin_Pages SHALL group stat cards into labeled sections (Users, Events, Photos, Subscriptions, Payments) with consistent section headings.
3. WHEN displaying tabular data (users, events, payments, audit logs), THE Admin_Pages SHALL use a consistent table pattern with column headers, row separators, and pagination controls.
4. WHEN displaying status values (user active/inactive, event active/archived, payment succeeded/failed), THE Admin_Pages SHALL use color-coded Badge variants consistently across all admin tables.
5. THE Admin_Pages SHALL use the existing `AppLayout` + `AdminLayout` wrapping as currently implemented.

---

### Requirement 10 — Responsive Design (14H)

**User Story:** As a mobile user, I want every page to be usable on a small screen, so that I can share and view photos from my phone during an event.

#### Acceptance Criteria

1. THE Landing_Page SHALL reflow to a single-column layout at viewport widths below 768 px, with the navigation menu collapsing to a hamburger toggle.
2. WHEN the viewport is below 768 px, THE Public_Event_Page and Gallery_Page SHALL display content in a single-column layout filling available width.
3. THE Event_Pages index grid SHALL use 1 column at < 640 px, 2 columns at ≥ 640 px, and 3 columns at ≥ 1024 px.
4. THE Admin_Pages stat-card grids SHALL stack to 1 column on mobile and expand to ≥ 4 columns on desktop.
5. WHILE the viewport is below 1024 px, THE PhotoViewer lightbox SHALL occupy at minimum 90 vw width.
6. THE Auth_Pages forms SHALL remain readable and usable at a minimum viewport width of 375 px.

---

### Requirement 11 — Accessibility (14I)

**User Story:** As a user with assistive technology, I want the application to be navigable by keyboard and readable by screen readers, so that I can use all features regardless of my abilities.

#### Acceptance Criteria

1. THE Landing_Page SHALL use semantic HTML5 sectioning elements (`<header>`, `<nav>`, `<main>`, `<section>`, `<footer>`) with appropriate ARIA landmark roles.
2. WHEN interactive elements are focused, THE Design_System SHALL display a visible focus ring using the `--ring` CSS custom property with at least 2 px outline offset.
3. THE PhotoUploader drag-and-drop zone SHALL be operable by keyboard (space/enter to open the file picker) with an `aria-label` describing its purpose.
4. THE PhotoViewer lightbox SHALL trap focus within the modal while open and restore focus to the trigger element on close.
5. WHEN a Skeleton placeholder is rendered, THE Skeleton component SHALL carry `aria-hidden="true"` or an `aria-busy="true"` on the container to prevent screen-reader confusion.
6. THE Auth_Pages SHALL associate every `<Label>` with its corresponding `<Input>` via matching `htmlFor` / `id` attributes.
7. THE Design_System SHALL ensure all text/background color combinations used in the revamp meet WCAG AA contrast ratio (≥ 4.5:1 for normal text, ≥ 3:1 for large text).

---

### Requirement 12 — Loading, Empty, and Error States (14J)

**User Story:** As a user, I want clear feedback when content is loading, missing, or unavailable, so that I understand the system state and know what to do next.

#### Acceptance Criteria

1. WHEN the Gallery_Page initial load is in progress, THE Gallery_Page SHALL display a grid of Skeleton placeholders sized to match the expected photo thumbnails.
2. WHEN the "Load More" fetch is in progress, THE Gallery_Page SHALL display additional Skeleton cards below the existing photos.
3. WHEN the Organizer_Dashboard has no recent events, THE Organizer_Dashboard SHALL display the EmptyState component with a "Create your first event" CTA.
4. WHEN a page-level error occurs (e.g., network failure on load-more), THE Gallery_Page SHALL display an inline error message with a "Try again" action rather than a blank area.
5. WHEN a form submission is processing, THE Auth_Pages and Event_Pages forms SHALL disable all form fields and show the Spinner component on the submit button.

---

### Requirement 13 — Micro-Interactions and Transitions (14K)

**User Story:** As a user, I want subtle animations and visual feedback, so that the application feels responsive and polished.

#### Acceptance Criteria

1. WHEN an interactive element (button, link, card) receives hover focus, THE Design_System SHALL apply a CSS transition of ≤ 200 ms on color, background-color, and box-shadow properties.
2. WHEN the PhotoViewer lightbox opens or closes, THE Gallery_Page SHALL animate the modal overlay with a fade transition (opacity 0 → 1) of ≤ 300 ms using Tailwind transition utilities.
3. WHEN a photo preview is added to the PhotoUploader grid, THE PhotoUploader SHALL animate the new preview in with a scale-up or fade-in transition.
4. WHEN a toast notification appears via `sonner`, THE Design_System SHALL allow the default toast animation to run uninterrupted.
5. WHEN the upload progress bar updates, THE PhotoUploader SHALL animate the bar width change with a CSS transition rather than an abrupt jump.

---

### Requirement 14 — Dark Mode (14L)

**User Story:** As a user who prefers dark mode, I want all revamped pages to respect my appearance preference, so that I can use the app comfortably in low-light conditions.

#### Acceptance Criteria

1. THE Landing_Page SHALL apply correct light and dark color values for all background, text, border, and accent elements using the existing `--background`, `--foreground`, `--muted`, `--border`, and `--brand` CSS custom properties.
2. THE Public_Event_Page gradient hero SHALL use CSS custom properties (not hardcoded colors) so it inverts correctly when the `.dark` class is applied.
3. THE Gallery_Page PhotoViewer overlay SHALL use `--background` and `--foreground` tokens so it renders correctly in dark mode.
4. THE Auth_Pages SHALL use only CSS custom property-backed Tailwind classes and shadcn/ui components, ensuring full dark-mode compatibility without separate dark-specific overrides.
5. THE Admin_Pages SHALL use only shadcn/ui Card, Badge, and table components styled via CSS custom properties, with no hardcoded light-mode colors.

---

### Requirement 15 — Performance (14M)

**User Story:** As a user on a slow connection, I want pages to load quickly and images to load progressively, so that I am not blocked waiting for content.

#### Acceptance Criteria

1. THE Gallery_Page SHALL load images lazily using the `loading="lazy"` attribute on `<img>` tags within the PhotoGrid component.
2. THE Landing_Page SHALL not import or bundle any third-party UI library not already present in `package.json`; all new components use Tailwind and shadcn/ui exclusively.
3. THE Public_Event_Page and Gallery_Page SHALL not re-render the entire photo list when a new page of photos is appended; only the new items are mounted.
4. THE PhotoUploader SHALL revoke object URLs for removed or submitted previews to prevent memory leaks, as it already does.
