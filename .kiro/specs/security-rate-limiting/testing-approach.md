# Phase 9 Security Tests — Property-Based Testing Approach

**Task:** 1.4 (security-rate-limiting)
**Status:** Decided and pinned.

## Chosen approach: `innmind/black-box` (adopted)

- **Library:** `innmind/black-box`
- **Version:** `6.12.0` (constraint `^6.12` in `composer.json` `require-dev`)
- **Installed via:** `composer require --dev innmind/black-box`
- **License:** MIT

### Why it was adopted
- Dependency resolution was clean: exactly **1 install, 0 updates, 0 removals**. It did **not** force any upgrade or downgrade of PHPUnit (`^12.5.23`), Laravel (`^13.17`), or any other core dependency.
- `composer show innmind/black-box` confirms the package is present (6.12.0).
- It is the library recommended in design.md → "Testing Strategy".

### Compatibility notes / how wave-2 tests must use it
- The existing Phase 5–8 tests under `tests/Feature` use plain **PHPUnit `#[Test]`** methods on `Tests\TestCase` with `RefreshDatabase` (this repo runs **PHPUnit 12**, not Pest — there is no `pestphp/pest` dependency).
- black-box provides **generators** (`Set`, etc.) usable from inside a PHPUnit test method. Wave-2 property tests should keep the established structure — a `#[Test]` method on `Tests\TestCase`, `RefreshDatabase`, `Storage::fake('public')` / `Queue::fake()` — and drive the property with black-box generators, OR, where a black-box run loop does not compose cleanly with `RefreshDatabase` / Laravel HTTP assertions, use a bounded loop that samples from black-box `Set`s.
- **Every property test MUST run a minimum of 100 iterations** regardless of the mechanism used.
- **Every property test MUST be tagged**: `// Feature: security-rate-limiting, Property {n}: {property_text}` and map to exactly one design property (Properties 1–8).

### Fallback (recorded for completeness, not currently in use)
If a future environment cannot install black-box cleanly, the repo-established fallback is **bounded-loop / data-provider generation with a minimum of 100 iterations per property test**, matching the existing Phase 5–8 convention (e.g. `QueuedPhotoProcessingTest`, `ImageProcessingTest`, `GuestPhotoUploadTest`, which achieve property coverage via bounded loops over randomized GD-synthesized inputs). In that case, revert the dependency with `git checkout -- composer.json composer.lock` and `composer install`.

## Verification performed for task 1.4
- `composer show innmind/black-box` → `6.12.0` present. ✅
- `php artisan test --filter=QueuedPhotoProcessingTest` → **10 passed**, 1 failed.
  - The single failure (`gallery_shows_only_ready`) is a **pre-existing environmental** issue:
    `Unable to locate file in Vite manifest: resources/js/pages/Public/Gallery.tsx`
    (the compiled frontend manifest is stale relative to source; the Gallery page is not in `public/build/manifest.json`). This is a Blade/Vite view-render concern with **no relationship** to a dev-only PHP test-generation library, which is not part of the HTTP/Vite pipeline. All 10 backend-logic tests pass.
  - Action for later: run `npm run build` (or `npm run dev`) before running tests that render the Gallery Inertia page. Not a regression introduced by this task.
