# Implementation Plan: Phase 14 — UI/UX Revamp

## Overview

This plan converts the Phase 14 design into discrete coding tasks organized by wave.
Each task touches one file or one closely related set of files. Tasks build on each other:
design foundation first, then public-facing pages, then authenticated pages, then
cross-cutting concerns, and finally a verification pass.

No backend files are modified **except** `WelcomeController.php` (task 2.1) which must
pass plan data to the landing page.

---

## Tasks

### Wave 1 — Design Foundation

- [x] 1. Add brand color tokens and Tailwind mappings to `app.css`
  - Open `resources/css/app.css`.
  - In `:root`, add `--brand: oklch(0.55 0.22 280);`, `--brand-foreground: oklch(0.985 0 0);`, `--brand-muted: oklch(0.55 0.22 280 / 0.12);`.
  - In `.dark`, add `--brand: oklch(0.72 0.19 280);`, `--brand-foreground: oklch(0.145 0 0);`, `--brand-muted: oklch(0.72 0.19 280 / 0.15);`.
  - In the `@theme {}` block, add `--color-brand: var(--brand);`, `--color-brand-foreground: var(--brand-foreground);`, `--color-brand-muted: var(--brand-muted);`.
  - _Requirements: 1.1, 1.2, 1.3, 14.1_

- [x] 2. Create `components/StatCard.tsx`
  - Accept props: `label: string`, `value: string | number`, `icon: React.ComponentType<{ className?: string }>`, `className?: string`.
  - Render a shadcn `Card` with `CardHeader` (label text + icon) and `CardContent` (large bold value).
  - Icon is rendered top-right of the header row (`flex flex-row items-center justify-between`).
  - Value uses `text-3xl font-bold`.
  - _Requirements: 9.1_

- [x] 3. Create `components/SectionHeader.tsx`
  - Accept props: `title: string`, `description?: string`, `action?: React.ReactNode`.
  - Render a `<div>` with a `<h2>` title (`text-xl font-semibold`) and optional muted-foreground description paragraph.
  - If `action` is provided, render it right-aligned on the same row as the title.
  - _Requirements: 9.2_

- [ ]* 4. Write unit tests for `StatCard` and `SectionHeader`
  - Verify `StatCard` renders the label, value string, and icon element.
  - Verify `SectionHeader` renders the title; renders description when provided; renders action when provided.
  - _Requirements: 9.1, 9.2_

- [x] 5. Checkpoint — Verify design foundation
  - Run `npx tsc --noEmit` in the project root. Fix any type errors before continuing.
  - Ensure all tests pass, ask the user if questions arise.

---

### Wave 2 — Marketing Landing Page (14A)

- [x] 6. Update `WelcomeController.php` to pass plan data as an Inertia prop
  - In `app/Http/Controllers/WelcomeController.php` (or equivalent), add a `plans` key to the `Inertia::render('welcome', [...])` call.
  - Read plan slugs (`free`, `pro`) from `config('plans')` and pass each as an array with `name`, `price`, `billing_interval`, `max_active_events`, `max_photos_per_event`, `max_storage_bytes`.
  - Update the TypeScript `welcome.tsx` props interface to include `plans: PlanData[]` where `PlanData` mirrors those fields.
  - _Requirements: 2.5_

- [x] 7. Create `components/landing/LandingNav.tsx`
  - Render a `<nav>` with the MomentGather logo/wordmark left-aligned.
  - Center links: "Features", "Pricing", "FAQ" as anchor links (`href="#features"`, etc.).
  - Right side: if `auth.user` exists, show "Go to Dashboard" `Button`; else show "Log in" `Link` and "Get Started" `Button`.
  - On mobile (< 768 px): hide the center links and right CTAs; show a hamburger `Button` that opens a shadcn `Sheet` with all links stacked vertically.
  - Apply `sticky top-0 z-50 bg-background/80 backdrop-blur-md border-b border-border` classes.
  - _Requirements: 2.1, 2.8, 10.1_

- [x] 8. Create `components/landing/HeroSection.tsx`
  - Full-width section with `min-h-[80vh]` and centered content.
  - Headline: "Everyone captures the moment. We gather them." — `text-4xl md:text-5xl lg:text-6xl font-bold leading-tight`. Style the word "gather" with `text-brand`.
  - Sub-headline: two lines of product value copy in `text-lg text-muted-foreground`.
  - CTA buttons: primary "Get Started Free" linking to register; secondary "See How It Works" anchor link.
  - Decorative background: a radial gradient using `--brand-muted` at low opacity, implemented as a `div` with `absolute inset-0 -z-10`.
  - _Requirements: 2.2, 14.1_

- [x] 9. Create `components/landing/HowItWorksSection.tsx`
  - `id="how-it-works"` section with `<SectionHeader title="How It Works" />`.
  - Four steps rendered as an ordered list: 1) Create an event & get your QR code, 2) Share the QR code at your event, 3) Guests scan and upload their photos, 4) Browse the shared gallery.
  - Each step: numbered circle (`bg-brand text-brand-foreground`), step title (`font-semibold`), step description (`text-muted-foreground text-sm`).
  - On desktop: horizontal layout with connecting lines between circles (`bg-border h-px`).
  - On mobile: vertical stacked list.
  - _Requirements: 2.3_

- [x] 10. Create `components/landing/FeaturesSection.tsx`
  - `id="features"` section with `<SectionHeader title="Everything you need to gather moments" />`.
  - Six feature cards in a `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6` layout.
  - Features: QR Code Sharing, Instant Galleries, Guest Uploads (no account), Drag & Drop, Photo Download, Secure & Private.
  - Each card: lucide-react icon in a `bg-brand-muted rounded-lg p-3` container, title, description.
  - _Requirements: 2.4_

- [x] 11. Create `components/landing/PricingSection.tsx`
  - `id="pricing"` section with `<SectionHeader title="Simple, transparent pricing" />`.
  - Accept `plans: PlanData[]` prop.
  - Render two plan cards side-by-side (`grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl mx-auto`).
  - Free plan card: border-border, list of limits, "Get Started Free" CTA.
  - Pro plan card: `ring-2 ring-brand`, "Most Popular" badge (`bg-brand text-brand-foreground`), same limit list, "Upgrade to Pro" CTA.
  - Price display: show `$0/mo` for free, format price from cents for pro.
  - _Requirements: 2.5_

- [x] 12. Create `components/landing/FaqSection.tsx`
  - `id="faq"` section with `<SectionHeader title="Frequently asked questions" />`.
  - Use shadcn `Accordion` with `type="single" collapsible`.
  - Minimum 6 Q&A pairs covering: what is MomentGather, how guests upload photos, whether an account is needed to view, photo storage, plan differences, how to get the QR code.
  - _Requirements: 2.6_

- [x] 13. Create `components/landing/CtaSection.tsx` and `components/landing/FooterSection.tsx`
  - `CtaSection`: full-width band with `bg-brand text-brand-foreground`, bold headline "Ready to gather your moments?", and "Get Started Free" button (`variant="secondary"` for contrast).
  - `FooterSection`: `<footer>` with MomentGather logo left, copyright center/right, navigation links (Features, Pricing, FAQ, Login).
  - _Requirements: 2.7_

- [x] 14. Rewrite `pages/welcome.tsx` to use landing components
  - Remove all existing Laravel-starter content from `welcome.tsx`.
  - Import and compose: `<LandingNav auth={auth} />`, `<HeroSection auth={auth} />`, `<HowItWorksSection />`, `<FeaturesSection />`, `<PricingSection plans={plans} />`, `<FaqSection />`, `<CtaSection />`, `<FooterSection />`.
  - Wrap in a `<main>` element with `role="main"` for accessibility.
  - Update props interface: `interface Props { auth: { user: User | null }; plans: PlanData[] }`.
  - _Requirements: 2.1–2.10, 11.1, 14.1_

- [x] 15. Checkpoint — Verify landing page
  - Run `npx tsc --noEmit`. Fix type errors.
  - Ensure all tests pass, ask the user if questions arise.

---

### Wave 3 — Auth Pages (14B)

- [x] 16. Add password strength indicator component `components/PasswordStrengthIndicator.tsx`
  - Accept `password: string` prop.
  - Compute strength: 0 (empty), 1 (< 8 chars), 2 (≥ 8 chars, letters only), 3 (≥ 8 chars with number or symbol), 4 (≥ 12 chars with number and symbol).
  - Render 4 small bars colored: gray (empty), red (1), orange (2), yellow (3), green (4).
  - Show a text label: "Too short", "Weak", "Fair", "Strong".
  - Uses only Tailwind utilities, no third-party library.
  - _Requirements: 3.6_

- [x] 17. Update `pages/auth/register.tsx` to use the password strength indicator
  - Read the current password field value via a controlled state or `watch`.
  - Render `<PasswordStrengthIndicator password={passwordValue} />` immediately below the password input.
  - Ensure label/input association uses `htmlFor` + `id`.
  - _Requirements: 3.3, 3.6, 11.6_

- [x] 18. Verify `pages/auth/login.tsx` accessibility and loading state
  - Confirm every `<Label>` has a matching `htmlFor` and `<Input>` has a matching `id`.
  - Confirm the submit button shows `<Spinner />` when `processing` is true (already implemented — verify and document).
  - Confirm field-level `<InputError>` messages appear below each input.
  - _Requirements: 3.1, 3.2, 3.3, 11.6_

- [x] 19. Verify remaining auth pages (`forgot-password.tsx`, `reset-password.tsx`, `verify-email.tsx`, `confirm-password.tsx`)
  - Check each for consistent label/input pairing.
  - Ensure submit button shows spinner when processing.
  - Add any missing `<InputError>` components for fields that lack them.
  - _Requirements: 3.2, 3.3, 3.5_

---

### Wave 4 — Public Event Page (14C)

- [x] 20. Redesign the drag-drop upload zone in `components/PhotoUploader.tsx`
  - Replace the plain "Select Photos" `Button` with a dedicated drag-drop zone `<div>`:
    - Classes: `border-2 border-dashed border-border rounded-xl p-8 flex flex-col items-center gap-3 cursor-pointer transition-colors duration-200`.
    - Drag-active state: add `border-brand bg-brand-muted` classes when `dragging` is true.
    - Contents: `<UploadCloud className="size-10 text-muted-foreground" />`, "Drag photos here" heading, "or click to browse" sub-text, "JPG, PNG, WEBP · Max 10 MB each" caption.
  - Keep the hidden `<input type="file">` and the `onClick` → `inputRef.current?.click()` wiring.
  - _Requirements: 4.3, 4.4, 13.1_

- [x] 21. Add upload progress bar to `components/PhotoUploader.tsx`
  - Below the preview grid (or below the upload zone if no previews), render a progress bar when `processing` is true.
  - Implementation: `<div className="h-1.5 rounded-full bg-muted overflow-hidden"><div className="h-full bg-brand transition-[width] duration-300 ease-out" style={{ width: `${progress?.percentage ?? 0}%` }} /></div>`.
  - Show the percentage as `text-xs text-muted-foreground text-center` below the bar.
  - _Requirements: 4.6, 13.5_

- [x] 22. Improve photo preview grid and add success state in `components/PhotoUploader.tsx`
  - Preview entrance animation: add `animate-in fade-in zoom-in-95 duration-150` (from `tw-animate-css`) to each `<li>` in the preview grid.
  - Success state: replace the current paragraph with a styled card containing `<CheckCircle2>` icon, "Photos uploaded!" heading, and a `Button asChild` "View Gallery" link pointing to `/e/${slug}/gallery`.
  - _Requirements: 4.5, 4.7, 13.3_

- [x] 23. Polish the hero band in `pages/Public/Event.tsx`
  - Update the gradient from `from-primary/10 via-muted` to `from-brand-muted via-muted`.
  - Add a decorative `<Camera>` lucide icon at 10% opacity in the top-right corner of the hero as a background element (`absolute top-4 right-4 text-brand opacity-10 size-24`).
  - _Requirements: 4.1, 14.2_

---

### Wave 5 — Photo Gallery (14D)

- [x] 24. Add sticky event header to `pages/Public/Gallery.tsx`
  - Add a `<header>` above the photo grid with `sticky top-0 z-10 bg-background/90 backdrop-blur-sm border-b border-border`.
  - Contents: event name (`font-semibold`), photo count (e.g., "42 photos" in `text-muted-foreground`), and a "Upload Photos" `Button` (variant="outline" size="sm") linking to `/e/{slug}`.
  - _Requirements: 5.7_

- [x] 25. Improve skeleton grid alignment in `pages/Public/Gallery.tsx`
  - Change the loading skeleton grid from hardcoded `grid-cols-2 sm:grid-cols-3 lg:grid-cols-4` to match the actual `PhotoGrid` column breakpoints.
  - Render 8 skeletons (not 4) so the loading state fills more of the viewport.
  - Ensure the skeleton container has `aria-busy="true"` and `aria-live="polite"`.
  - _Requirements: 5.2, 11.5, 12.2_

- [x] 26. Add load-more error handling to `pages/Public/Gallery.tsx`
  - Add `loadError: boolean` state (default false).
  - In the `router.get` `onError` callback, set `loadError(true)` and `setLoading(false)`.
  - When `loadError` is true, display an inline alert: "Couldn't load more photos." with a "Try again" button that clears the error and calls `loadMore()`.
  - _Requirements: 12.4_

- [x] 27. Ensure `loading="lazy"` on images in `components/PhotoGrid.tsx`
  - Read `PhotoGrid.tsx` and verify each `<img>` has `loading="lazy"`.
  - Add it if missing.
  - _Requirements: 15.1_

- [x] 28. Polish `components/PhotoViewer.tsx` for transitions and accessibility
  - Add `transition-opacity duration-200` (or `animate-in fade-in duration-200` from tw-animate-css) to the overlay backdrop.
  - Verify `aria-modal="true"` and `role="dialog"` on the modal root.
  - Verify Escape key closes the viewer and focus is returned to the triggering thumbnail.
  - Add `aria-label="Previous photo"` and `aria-label="Next photo"` to navigation buttons if missing.
  - _Requirements: 5.4, 5.5, 11.4, 13.2_

---

### Wave 6 — Organizer Dashboard & Events (14E)

- [x] 29. Refactor `pages/dashboard.tsx` to use `StatCard`
  - Replace the three inline stat card JSX blocks with `<StatCard label={...} value={stats[key]} icon={Icon} />`.
  - Ensure the stat section uses `grid gap-4 sm:grid-cols-3`.
  - _Requirements: 6.1_

- [x] 30. Improve the recent events list in `pages/dashboard.tsx`
  - Each list item: show event name (truncated, `font-medium`), status badge (colored), event date in `text-sm text-muted-foreground`, and a small `<Images className="size-3.5">` icon with photo count if the prop includes it.
  - The "Create Event" button should be `variant="default"` (filled, uses brand color now) with a `<Plus>` icon.
  - _Requirements: 6.2, 6.4_

- [x] 31. Verify `pages/Events/Index.tsx` responsive grid and card polish
  - Confirm the grid uses `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`.
  - Add a `photo_count` display inside each card's `CardContent` if the prop is available from the controller.
  - Improve upload status display: replace plain text with a `Badge` (variant="outline" when enabled, variant="secondary" when disabled).
  - _Requirements: 7.1, 7.2, 10.3_

- [x] 32. Verify `pages/Events/Show.tsx` layout and QR code section
  - Confirm the page groups content into: "Event Info" card, "QR Code" card, "Share & View" card.
  - In the QR code card, add instructional text "Point your phone camera at this code to open the upload page" below the QR image.
  - Confirm the delete confirmation Dialog is accessible (has `aria-describedby` pointing to the description).
  - _Requirements: 7.4, 7.5_

- [x] 33. Verify `pages/Events/Create.tsx` and `pages/Events/Edit.tsx` form consistency
  - Check both forms have consistent label-above-input spacing (`grid gap-2` per field).
  - Add a character count helper below the description textarea: `{description.length}/500` in `text-xs text-muted-foreground`.
  - Ensure the submit button shows `<Spinner />` when processing.
  - _Requirements: 7.3, 3.2_

---

### Wave 7 — Billing Settings (14F)

- [x] 34. Polish `components/billing/UsageMeter.tsx` progress bar transition
  - Read `UsageMeter.tsx`. Add `transition-[width] duration-500 ease-out` to the progress bar fill element.
  - Ensure the bar has an `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax` set to the limit value and `role="progressbar"`.
  - _Requirements: 8.2, 13.1_

- [x] 35. Polish `components/billing/PlanCard.tsx` with brand highlight for Pro
  - When the `current` prop is true AND the plan is Pro, add `ring-2 ring-brand` to the card.
  - Add a "Current Plan" badge (`variant="default"`) to the card header when `current` is true.
  - _Requirements: 8.1_

- [x] 36. Verify `pages/settings/billing.tsx` all conditional states render correctly
  - Walk through all four plan states (free, pro-active, pro-cancelling, pro-ended) and confirm the correct UI renders based on existing logic.
  - Ensure the cancellation scheduled date uses `formatDate` and is grammatically correct.
  - No functional changes — verify and fix display-only issues only.
  - _Requirements: 8.3, 8.4, 8.5, 8.6_

---

### Wave 8 — Admin UI (14G)

- [x] 37. Refactor `pages/Admin/Dashboard.tsx` to use `StatCard` and `SectionHeader`
  - Replace all 17+ manual Card/CardHeader/CardContent stat patterns with `<StatCard />`.
  - Replace inline `<h2>` section headings with `<SectionHeader title="..." />`.
  - Import the icons from lucide-react for each stat group (Users → `Users`, Events → `CalendarDays`, Photos → `Image`, Subscriptions → `CreditCard`, Payments → `DollarSign`).
  - _Requirements: 9.1, 9.2_

- [x] 38. Audit Badge variants across `pages/Admin/` pages for consistency
  - Open each admin page that shows status badges: Users (active/inactive), Events (active/archived/draft), Payments (succeeded/failed), Subscriptions (active/canceled).
  - Standardize: `variant="default"` for active/succeeded, `variant="secondary"` for inactive/archived/draft, `variant="destructive"` for failed/canceled.
  - _Requirements: 9.4_

- [x] 39. Verify admin tables have consistent column headers and row separators
  - Open `pages/Admin/Users/`, `pages/Admin/Payments/`, `pages/Admin/AuditLogs/` index pages.
  - Confirm each uses `<table>` or a structured list with visible column labels.
  - Add `divide-y divide-border` to table bodies if missing.
  - _Requirements: 9.3_

---

### Wave 9 — Cross-Cutting: Responsive, Accessibility, States, Micro-interactions (14H–14M)

- [x] 40. Verify `Landing_Page` responsive breakpoints
  - At < 768 px: confirm hamburger nav, single-column sections, stacked pricing cards.
  - At ≥ 768 px: confirm 2-column features grid.
  - At ≥ 1280 px: confirm 3-column features, side-by-side pricing.
  - Fix any broken layout by adjusting Tailwind responsive prefixes.
  - _Requirements: 2.9, 10.1_

- [x] 41. Verify `Public_Event_Page` and `Gallery_Page` mobile layout
  - At ≤ 375 px: confirm content is readable and doesn't overflow horizontally.
  - Upload zone should be full-width.
  - Gallery grid: at least 2 columns on mobile.
  - _Requirements: 5.1, 10.2_

- [x] 42. Verify event cards grid responsive breakpoints in `Events/Index.tsx`
  - At < 640 px: 1 column.
  - At ≥ 640 px: 2 columns.
  - At ≥ 1024 px: 3 columns.
  - _Requirements: 10.3_

- [x] 43. Audit focus ring visibility across all new components
  - Inspect every interactive element added in this revamp (buttons, links, accordion triggers, hamburger menu, drag-drop zone, photo thumbnails).
  - Ensure `focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2` (or equivalent) is applied.
  - Add `focus-visible:outline-none` to remove default browser outline only where the custom ring is present.
  - _Requirements: 11.2_

- [x] 44. Verify `PhotoUploader` keyboard accessibility
  - Confirm the drag-drop zone `<div>` has `tabIndex={0}`, `role="button"`, `aria-label="Upload photos — press Enter or Space to browse files"`.
  - Confirm pressing Enter or Space fires `inputRef.current?.click()` via an `onKeyDown` handler.
  - _Requirements: 4.3, 11.3_

- [x] 45. Add `aria-hidden="true"` to decorative elements and verify Skeleton accessibility
  - In `HeroSection.tsx`: add `aria-hidden="true"` to the decorative gradient orb `<div>`.
  - In `HowItWorksSection.tsx`: add `aria-hidden="true"` to the connecting lines between step circles.
  - In `Gallery.tsx` loading skeleton grid: confirm `aria-busy="true"` is on the container.
  - _Requirements: 11.5_

- [x] 46. Verify dark mode compliance for landing page components
  - Open browser in dark mode (or apply `.dark` class to `<html>`).
  - Verify `LandingNav`, `HeroSection`, `PricingSection`, `FooterSection` use only CSS-custom-property-backed classes.
  - Replace any hardcoded hex/rgb colors found with appropriate token classes.
  - _Requirements: 14.1_

- [x] 47. Verify dark mode compliance for `Public_Event_Page` and `Gallery_Page`
  - Confirm the hero gradient uses `--brand-muted` (not hardcoded `primary/10`).
  - Confirm `PhotoViewer` backdrop uses `bg-background/90` or equivalent token.
  - _Requirements: 14.2, 14.3_

- [x] 48. Verify hover transitions are ≤ 200 ms across interactive elements
  - Cards in Events/Index.tsx and Dashboard should have `transition-colors duration-150` on hover states.
  - Stat cards: add `hover:border-brand transition-colors duration-150` or similar subtle brand-color accent.
  - Buttons already use shadcn transitions — verify no regression.
  - _Requirements: 13.1_

- [x] 49. Add `loading="lazy"` and verify no unnecessary re-renders in Gallery
  - Confirm `PhotoGrid.tsx` has `loading="lazy"` (from task 27 — re-verify here).
  - Profile with React DevTools: appending new photos via `setItems(prev => [...prev, ...next])` should not re-render existing items. Verify the key is `photo.uuid`.
  - _Requirements: 15.1, 15.3_

---

### Wave 10 — Verification

- [x] 50. TypeScript compilation check
  - Run `npx tsc --noEmit` in `c:\xampp\htdocs\moment-gather`.
  - Fix all type errors introduced by new components or modified props interfaces.
  - _Requirements: all_

- [x] 51. Build verification
  - Run `npm run build` (Vite).
  - Fix any bundling errors (missing imports, invalid CSS, etc.).
  - _Requirements: 15.2_

- [ ]* 52. Run existing test suite
  - Run `php artisan test --parallel` to confirm no backend regressions.
  - Run any JS test suite if configured (`npm test -- --run` or `npx vitest --run`).
  - _Requirements: all_

- [x] 53. Final checkpoint — Manual verification walkthrough
  - Verify landing page: all sections visible on desktop; mobile nav collapses to hamburger; dark mode correct.
  - Verify auth pages: login spinner works; register shows password strength.
  - Verify public event page: drag-drop zone shows; brand gradient in hero; progress bar animates.
  - Verify gallery: sticky header; skeletons on load; lightbox opens/closes with transitions.
  - Verify dashboard: StatCard renders; empty state CTA present.
  - Verify billing: plan badge correct; UsageMeter animates.
  - Verify admin dashboard: StatCard components in use; section headers present.
  - Ensure all tests pass, ask the user if questions arise.

---

## Notes

- Tasks marked with `*` are optional (unit/integration tests) and can be skipped for faster MVP delivery.
- Tasks 1–5 (Wave 1) must complete before any other wave begins — they establish the shared components.
- Task 6 (`WelcomeController.php`) is the only backend file change in this entire phase.
- Design tokens from task 1 (`bg-brand`, `text-brand`, `bg-brand-muted`) are used throughout waves 2–9.
- When a task says "verify", read the target file before making changes to avoid regressions.
- The existing `PhotoUploader` drag-and-drop logic is already implemented; tasks 20–22 improve the visual presentation only.
- The existing `settings/billing.tsx` is already well-implemented; tasks 34–36 are polish only.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "2", "3"] },
    { "id": 1, "tasks": ["4", "5"] },
    { "id": 2, "tasks": ["6", "7", "8", "9", "10", "11", "12", "13", "16"] },
    { "id": 3, "tasks": ["14", "17", "18", "19", "20", "24", "29", "34", "37"] },
    { "id": 4, "tasks": ["15", "21", "22", "25", "26", "27", "28", "30", "31", "32", "33", "35", "36", "38", "39"] },
    { "id": 5, "tasks": ["23", "40", "41", "42", "43", "44", "45", "46", "47", "48", "49"] },
    { "id": 6, "tasks": ["50", "51", "52"] },
    { "id": 7, "tasks": ["53"] }
  ]
}
```
