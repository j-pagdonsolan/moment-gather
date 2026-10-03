# Design Document — Phase 14: UI/UX Revamp

## Overview

This design transforms MomentGather from a functional MVP into a polished, production-ready
product. The approach is **additive and conservative**: we extend what exists rather than
replacing architecture. Every layout, route, controller, and Inertia page component is
kept; only their visual presentation is upgraded.

Key design decisions:
- Introduce a brand accent color (violet) layered on top of the existing neutral shadcn token system.
- Replace `welcome.tsx` wholesale with a real marketing landing page.
- Polish existing pages (dashboard, events, public event, gallery, auth, billing, admin)
  without changing their data contracts.
- Extract shared UI patterns (Stat_Card, Section_Header, etc.) into reusable components.
- All new UI uses existing Tailwind v4 utilities and shadcn/ui — no new dependencies.

---

## Architecture

The project already has a clean layered architecture. This revamp operates entirely within
the **presentation layer** (the React/TypeScript files under `resources/js/`).

```
resources/js/
├── app.css                   ← Add brand tokens here
├── app.tsx                   ← Layout resolver — unchanged
├── components/
│   ├── ui/                   ← shadcn/ui — use as-is, no modifications
│   ├── billing/              ← Already polished — minor tweaks only
│   ├── PhotoUploader.tsx     ← Polish drag-drop zone and progress bar
│   ├── PhotoGrid.tsx         ← Ensure lazy loading
│   ├── PhotoViewer.tsx       ← Polish lightbox transitions
│   └── [new shared comps]   ← SectionHeader, StatCard, LandingNav, etc.
├── layouts/                  ← All layouts unchanged
└── pages/
    ├── welcome.tsx           ← Full replacement
    ├── dashboard.tsx         ← Polish
    ├── Public/
    │   ├── Event.tsx         ← Polish
    │   └── Gallery.tsx       ← Polish
    ├── Events/               ← Polish
    ├── settings/billing.tsx  ← Minor polish (already good)
    ├── auth/                 ← Minor polish
    └── Admin/                ← Polish Dashboard.tsx, add StatCard
```

No new routes, controllers, migrations, or backend files are added.

---

## Components and Interfaces

### New Shared Components

#### `components/StatCard.tsx`
Used by `Organizer_Dashboard` and `Admin/Dashboard.tsx`.

```tsx
interface StatCardProps {
  label: string;
  value: string | number;
  icon: React.ComponentType<{ className?: string }>;
  trend?: 'up' | 'down' | 'neutral';  // optional future use
  className?: string;
}
```

Renders a shadcn `Card` with a muted-foreground label, bold large value, and icon top-right.
Replaces the inline card repetition in both dashboard files.

#### `components/SectionHeader.tsx`
A lightweight heading + optional description used for page sections.

```tsx
interface SectionHeaderProps {
  title: string;
  description?: string;
  action?: React.ReactNode;
}
```

Used on the landing page sections, admin dashboard section groups, and organizer dashboard.

#### `components/landing/LandingNav.tsx`
The marketing navigation bar for `welcome.tsx`.

- Desktop: horizontal nav with logo, links, and CTAs.
- Mobile (< 768 px): hamburger menu that opens a Sheet (shadcn `Sheet`) containing all links.
- Uses `usePage().props.auth.user` to swap "Get Started" / "Log in" for "Go to Dashboard".

#### `components/landing/HeroSection.tsx`
Hero band for `welcome.tsx`:
- Large headline + sub-headline.
- Primary CTA button (`Button` variant="default" with brand color).
- Optional decorative gradient orb using `--brand` color at low opacity.

#### `components/landing/HowItWorksSection.tsx`
Four-step numbered list explaining the guest flow. Uses an ordered-list pattern with numbered circles (brand color), a title, and a description per step.

#### `components/landing/FeaturesSection.tsx`
Feature grid — 2 columns tablet, 4 columns desktop. Each feature card: icon (lucide-react), title, description.

#### `components/landing/PricingSection.tsx`
Accepts `plans: Plan[]` as a prop (passed from the controller). Renders side-by-side plan cards. Uses the existing `PlanCard` component or a standalone variant.

#### `components/landing/FaqSection.tsx`
Accordion-based FAQ using shadcn `Accordion` component. Five or more Q&A pairs.

#### `components/landing/CtaSection.tsx` + `components/landing/FooterSection.tsx`
Final CTA band and site footer.

### Existing Components — What Changes

| Component | Change |
|-----------|--------|
| `PhotoUploader.tsx` | Add progress-bar animation (`<progress>` or Tailwind `w-[{n}%]` transition), improve drag-drop zone visual (dashed border, icon, copy), add success state "View Gallery" link |
| `PhotoGrid.tsx` | Ensure `loading="lazy"` on `<img>` tags |
| `PhotoViewer.tsx` | Add fade transition on open/close (Tailwind `transition-opacity duration-200`), ensure focus trap, ensure keyboard nav labels |
| `empty-state.tsx` | No change needed — already good |
| `heading.tsx` | No change needed |
| `billing/PlanCard.tsx` | Minor: add brand-accent badge for "Current Plan" |
| `billing/UsageMeter.tsx` | Minor: add transition on progress bar width |

### Unchanged Components

All `components/ui/*` shadcn components — use as-is.
All `layouts/*` — no modifications.

---

## Data Models

No new data models. The revamp consumes existing Inertia page props:

| Page | Key Props |
|------|-----------|
| `welcome.tsx` | `auth.user`, `plans` (array of plan objects from `WelcomeController`) |
| `dashboard.tsx` | `stats: DashboardStats`, `recentEvents: Event[]` |
| `Public/Event.tsx` | `event: PublicEvent` |
| `Public/Gallery.tsx` | `event`, `photos: GalleryPhoto[]`, `pagination` |
| `settings/billing.tsx` | `plan`, `isPro`, `subscription`, `usage`, `payments`, `currency` |
| `Admin/Dashboard.tsx` | `stats: AdminDashboardStats` |

The `welcome.tsx` page currently receives `auth` and `name` from the server. The controller
will need to additionally pass `plans` (an array of plan objects). This is a minimal backend
change confined to `WelcomeController.php` — it reads from `config('plans')` and passes the
data as an Inertia prop. No model or migration changes.

---

## Color Palette / Design Tokens

### Brand Color Addition

The current palette is purely neutral (oklch with zero chroma). We add a violet accent:

```css
/* app.css — :root additions */
--brand: oklch(0.55 0.22 280);          /* violet-600 equivalent */
--brand-foreground: oklch(0.985 0 0);   /* near-white */
--brand-muted: oklch(0.55 0.22 280 / 0.12); /* transparent tint for backgrounds */

/* app.css — .dark additions */
--brand: oklch(0.72 0.19 280);          /* lighter violet for dark bg */
--brand-foreground: oklch(0.145 0 0);   /* near-black */
--brand-muted: oklch(0.72 0.19 280 / 0.15);
```

These map into Tailwind via the `@theme` block:
```css
@theme {
  --color-brand: var(--brand);
  --color-brand-foreground: var(--brand-foreground);
  --color-brand-muted: var(--brand-muted);
}
```

Usage: `bg-brand`, `text-brand`, `bg-brand-muted`, `border-brand`, `text-brand-foreground`.

### Updated Token Usage Guide

| Token | Light | Dark | Use |
|-------|-------|------|-----|
| `--background` | white | near-black | page backgrounds |
| `--foreground` | near-black | near-white | body text |
| `--muted` | light gray | dark gray | section backgrounds |
| `--muted-foreground` | medium gray | lighter gray | secondary text |
| `--brand` | violet-600 | violet-400 | CTAs, active states, accents |
| `--brand-foreground` | white | dark | text on brand surfaces |
| `--brand-muted` | violet/12% | violet/15% | subtle tints |
| `--border` | light gray | dark gray | dividers |
| `--ring` | gray-400 | gray-600 | focus outlines |
| `--destructive` | red | red | errors, destructive actions |

### Why Violet?

- Distinctive from the default shadcn neutral palette.
- Works well in both light and dark modes with oklch color space.
- Conveys creativity and trust — appropriate for a photo-sharing product.
- High contrast against both white and dark backgrounds with the chosen lightness values.

---

## Typography Scale

MomentGather uses **Instrument Sans** (already loaded in `app.css`). The scale:

| Class | Size | Weight | Use |
|-------|------|--------|-----|
| `text-xs` | 12px | 400 | Caption, helper text |
| `text-sm` | 14px | 400/500 | Labels, secondary copy, badges |
| `text-base` | 16px | 400 | Body copy |
| `text-lg` | 18px | 500/600 | Card titles, sub-headings |
| `text-xl` | 20px | 600 | Section titles |
| `text-2xl` | 24px | 700 | Page headings |
| `text-3xl` | 30px | 700 | Hero / landing headline |
| `text-4xl–5xl` | 36–48px | 700/800 | Landing hero large screens |

Line heights: `leading-normal` (1.5) for body; `leading-tight` (1.25) for headings.

---

## Per-Page Design Decisions

### 14A — Marketing Landing Page (`welcome.tsx`)

**Overall approach:** Full component replacement. The file becomes a single-page marketing
layout assembled from focused sub-components, all in a `components/landing/` directory.

**Structure:**
```
<LandingNav />                    ← sticky on scroll, bg/backdrop-blur
<HeroSection />                   ← full-viewport-height, centered content
<HowItWorksSection />             ← numbered 4-step horizontal stepper
<FeaturesSection />               ← icon + title + description grid
<PricingSection plans={plans} />  ← plan cards from backend prop
<FaqSection />                    ← shadcn Accordion
<CtaSection />                    ← final call to action
<FooterSection />                 ← links + copyright
```

**Visual choices:**
- Hero: large centered headline (`text-4xl md:text-6xl`), gradient text using `--brand`
  on the word "gather", muted background with a radial gradient orb.
- Nav sticky with `bg-background/80 backdrop-blur-md` on scroll.
- How It Works: horizontal step list with numbered circles (`bg-brand text-brand-foreground`),
  connecting lines (`bg-border`), and step descriptions.
- Pricing cards: use existing `PlanCard` component; the Pro card gets a `ring-2 ring-brand`
  highlight and a "Most Popular" badge.
- FAQ: shadcn `Accordion` with `AccordionItem` per question.
- Footer: two-column layout (logo + tagline left, links right) on desktop; stacked on mobile.

**Backend change needed:** `WelcomeController.php` must pass `plans` as an Inertia prop.
This is the only backend file touched in this phase.

---

### 14B — Auth Pages (`pages/auth/`)

**Overall approach:** The existing `AuthSimpleLayout` already provides a good centered
structure. Changes are minimal:

- Login and register: add a MomentGather wordmark or logo icon above the form title
  (already partially done — the AppLogoIcon is shown).
- Register: add a simple password strength indicator below the password field. This is a
  small inline component that computes a "weak/medium/strong" label from the password length
  and character variety. No third-party library.
- Status message styling: use `text-green-600` success already present; improve error state
  alignment.
- All existing `Spinner` and `InputError` usage stays.

**No layout changes** — `AuthLayout` → `AuthSimpleLayout` chain is untouched.

---

### 14C — Public Event Page (`pages/Public/Event.tsx`)

**Overall approach:** The existing structure is good. Targeted improvements:

**Hero band:**
- Change from `from-primary/10 via-muted` to `from-brand-muted via-muted` gradient.
- Add a subtle decorative photography icon (lucide `Camera`) at low opacity in the hero
  background for visual interest.

**Upload zone improvements to PhotoUploader:**
- Replace the plain "Select Photos" button with a proper drag-drop zone:
  ```
  ┌─────────────────────────────────────────┐
  │  ↑ Upload icon (24px)                   │
  │  Drag photos here                        │
  │  or click to browse                     │
  │  JPG, PNG, WEBP accepted                │
  └─────────────────────────────────────────┘
  ```
  Rendered as a `<div>` with `border-2 border-dashed border-border rounded-xl p-8 cursor-pointer`.
- Dragging active state: `border-brand bg-brand-muted`.
- Photo preview grid: keep existing 3-col grid, add smooth `scale-95 → scale-100` entrance animation.
- Progress bar: `<div className="h-1.5 rounded-full bg-muted"><div className="h-full bg-brand transition-[width] duration-300" style={{width: `${progress?.percentage ?? 0}%`}} /></div>`
- Success state: green checkmark + "View Gallery" link button.

**No change** to the stepper steps, gallery link button, or the upload-disabled notice.

---

### 14D — Photo Gallery (`pages/Public/Gallery.tsx`)

**Overall approach:** The existing logic (load-more pagination, PhotoViewer, deduplication)
is solid. Visual improvements:

**Header:**
- Add a sticky header band with event name, photo count, and "Upload Photos" link.
- Use `position: sticky; top: 0` with `bg-background/90 backdrop-blur-sm border-b`.

**Grid:**
- Ensure `PhotoGrid` uses `loading="lazy"` on images.
- Ensure skeleton grid matches real grid column count (current skeleton uses 4 cols but grid may vary).

**PhotoViewer:**
- Add `transition-opacity duration-200` to the overlay backdrop.
- Ensure `aria-modal="true"`, focus trap, and Escape-key handler (verify existing).
- Add download button if not already present on every viewport size.

**Empty state:**
- Already uses `EmptyState` — no change.

---

### 14E — Organizer Dashboard (`pages/dashboard.tsx`)

**Overall approach:** Extract the stat card pattern into `StatCard` component.
Add photo count to each event row in the recent events list.

**Stat cards:**
- Use new `StatCard` component: label + large number + icon, with subtle border.
- Add a thin colored top-border on hover using `--brand` to make them feel interactive.

**Recent events list:**
- Add `photo_count` to the event rows if provided by the backend (check `DashboardStats` type).
- Improve the list items: show a small camera icon with photo count alongside the badge.

**Layout:** No sidebar changes. Breadcrumbs unchanged.

---

### 14E (continued) — Event Management Pages

**Index (`Events/Index.tsx`):**
- Cards already good. Add photo count badge to each card (if `photo_count` in props).
- Improve `CardFooter` button spacing.

**Show (`Events/Show.tsx`):**
- Group details into two logical sections: "Event Info" and "Share & Publish".
- Improve QR code section: add a subtle dashed border container, instruction text.

**Create/Edit (`Events/Create.tsx`, `Events/Edit.tsx`):**
- Verify consistent label-above-input spacing. Add character count for description field.
- No functional changes.

---

### 14F — Billing Settings Page (`pages/settings/billing.tsx`)

The billing page is already well-implemented with `PlanCard`, `UsageMeter`,
`CancelSubscriptionDialog`, and `PaymentHistoryList`. Changes are cosmetic:

- Add `ring-2 ring-brand` highlight to the Pro plan card when the user is on Pro.
- `UsageMeter`: animate the progress bar width with `transition-[width] duration-500`.
- `PlanCard`: ensure both light/dark modes render correctly.
- No functional or data contract changes.

---

### 14G — Admin Dashboard (`pages/Admin/Dashboard.tsx`)

**Current state:** The file manually repeats `<Card><CardHeader>...</CardHeader><CardContent>...</CardContent></Card>` for every metric. There are 17+ individual stat cards.

**Change:** Replace every stat card with `<StatCard label="..." value={...} icon={IconComponent} />`.
This reduces the file from ~250 lines to ~80 lines while improving consistency.

**Section headings:** Replace inline `<h2>` tags with `<SectionHeader title="..." />`.

**Recent payments table:** Already uses a clean card list. Minor: add a `text-muted-foreground text-xs` timestamp and ensure badge colors are consistent with the color guide.

**Other admin pages:** Verify tables use consistent `Badge` variants for status columns.
No full rewrites — targeted badge-variant corrections only.

---

## Correctness Properties

Property-based testing is **not applicable** to this feature. The UI/UX revamp is entirely
concerned with:

1. CSS variable/token definitions (configuration)
2. React component rendering (visual)
3. Conditional state display (specific examples)
4. Layout and responsiveness (visual regression)
5. Accessibility attribute presence (structural)

None of these categories benefit from universal quantification across a wide input space.
The one computable logic point (photo deduplication in `Gallery.tsx` load-more) is a trivial
`Set`-based filter already covered by TypeScript type safety and is not complex enough to
warrant a PBT harness.

Testing strategy is defined in the Testing Strategy section below.

---

## Error Handling

### Upload Errors
`PhotoUploader` already handles:
- Server validation errors → displayed below the form
- HTTP 429 rate limit → `rateLimited` state + friendly message
- The component keeps the photo selection intact on error for retry

No changes to error handling logic — only visual polish (error messages styled with
`text-destructive text-sm`, aligned to form).

### Gallery Load-More Errors
Currently `Gallery.tsx` does not handle `router.get` failures during load-more. Add:
```tsx
onError: () => {
  setLoadError(true);
  setLoading(false);
}
```
Display an inline error card: "Couldn't load more photos. Try again." with a retry button.

### Form Submission Errors (Auth, Events)
Already handled by Inertia's `useForm` errors object. Visual polish only.

### 404 / Deactivated Events
`Public/Event.tsx` and `Public/Gallery.tsx` receive their data from the controller;
error routing is handled at the Laravel level — no frontend change needed.

---

## Testing Strategy

This feature is UI-only. The appropriate testing approach is:

### TypeScript Type Checking
- Run `tsc --noEmit` to verify all new component props match their TypeScript interfaces.
- Run `vite build` to ensure no bundling errors.

### Unit Tests (Vitest + React Testing Library)
Where a testing setup exists, write example-based tests for:
- `StatCard` renders label, value, and icon.
- `LandingNav` renders "Go to Dashboard" when `auth.user` is present.
- `LandingNav` renders "Log in" and "Get Started" when `auth.user` is null.
- `PhotoUploader` shows the drag-drop zone in idle state.
- `PhotoUploader` shows progress bar when `progress` prop is non-null.
- Gallery deduplication: given items [A, B] and new batch [B, C], merged result is [A, B, C].

### Manual Verification Checklist
- [ ] Landing page renders all sections on desktop and mobile.
- [ ] Dark mode: all pages look correct with `.dark` class applied.
- [ ] Auth pages: login form shows spinner on submit, error on failure.
- [ ] Upload: drag-drop zone highlights on drag-over, shows progress, success, and error states.
- [ ] Gallery: skeleton shows on load, lightbox opens/closes, keyboard nav works.
- [ ] Dashboard: stat cards render, empty state shows, event list renders.
- [ ] Billing: plan badge correct, usage meters fill, cancel dialog works.
- [ ] Admin dashboard: StatCard components render, section headings present.
- [ ] `tsc --noEmit` passes.
- [ ] `npm run build` succeeds.
