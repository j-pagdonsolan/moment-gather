<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Plan;
use App\Models\PlanOverride;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlanController
{
    /**
     * Display the list of plans with their effective limits and source flags.
     *
     * For each plan slug in config('plans'), we load the effective Plan via
     * Plan::fromEffectiveConfig() and the PlanOverride row to determine which
     * fields come from override vs config. Each field gets a source flag:
     * 'override' when the override row exists and the field is non-null,
     * else 'config'.
     */
    public function index(): Response
    {
        Gate::authorize('manage-plans');

        $configuredPlans = config('plans', []);
        $plans = [];

        foreach (array_keys($configuredPlans) as $slug) {
            // Load the effective merged plan
            $effectivePlan = Plan::fromEffectiveConfig($slug);

            // Load the override row to determine source flags
            $override = PlanOverride::where('slug', $slug)->first();

            // Determine source flag per field
            $priceSource              = ($override && $override->price !== null)               ? 'override' : 'config';
            $maxActiveEventsSource    = ($override && $override->max_active_events !== null)   ? 'override' : 'config';
            $maxPhotosPerEventSource  = ($override && $override->max_photos_per_event !== null) ? 'override' : 'config';
            $maxStorageBytesSource    = ($override && $override->max_storage_bytes !== null)   ? 'override' : 'config';

            // is_active: true if no override, else override's is_active value
            $isActive = $override ? $override->is_active : true;

            $plans[] = [
                'slug'                           => $effectivePlan->slug,
                'name'                           => $effectivePlan->name,
                'price'                          => $effectivePlan->price,
                'price_source'                   => $priceSource,
                'max_active_events'              => $effectivePlan->maxActiveEvents,
                'max_active_events_source'       => $maxActiveEventsSource,
                'max_photos_per_event'           => $effectivePlan->maxPhotosPerEvent,
                'max_photos_per_event_source'    => $maxPhotosPerEventSource,
                'max_storage_bytes'              => $effectivePlan->maxStorageBytes,
                'max_storage_bytes_source'       => $maxStorageBytesSource,
                'is_active'                      => $isActive,
            ];
        }

        return Inertia::render('Admin/Plans/Index', [
            'plans' => $plans,
        ]);
    }

    /**
     * Update plan limits via plan_overrides.
     *
     * Creates or updates a PlanOverride row with the submitted limit values.
     * Logs the change to the audit log with old vs new values.
     */
    public function update(string $slug, Request $request, AuditLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-plans');

        $validated = $request->validate([
            'max_active_events'    => 'nullable|integer|min:0',
            'max_photos_per_event' => 'nullable|integer|min:0',
            'max_storage_bytes'    => 'nullable|integer|min:0',
            'price'                => 'nullable|integer|min:0',
        ]);

        // Capture old state for audit log
        $old = PlanOverride::where('slug', $slug)->first();

        // Create or update the override
        $new = PlanOverride::updateOrCreate(
            ['slug' => $slug],
            array_merge($validated, ['is_active' => true])
        );

        // Audit log
        $logger->log(
            $request->user(),
            'plan_limit_updated',
            'PlanOverride',
            $new->id,
            "Plan '{$slug}' limits updated.",
            [
                'old' => $old?->toArray(),
                'new' => $new->toArray(),
            ]
        );

        return redirect()->back()->with('success', 'Plan limits updated.');
    }

    /**
     * Deactivate a plan by setting is_active = false in plan_overrides.
     *
     * Does NOT cascade-delete user content; existing subscriptions and events
     * remain unaffected.
     */
    public function deactivate(string $slug, Request $request, AuditLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-plans');

        $override = PlanOverride::updateOrCreate(
            ['slug' => $slug],
            ['is_active' => false]
        );

        $logger->log(
            $request->user(),
            'plan_deactivated',
            'PlanOverride',
            $override->id,
            "Plan '{$slug}' deactivated.",
            []
        );

        return redirect()->back()->with('success', 'Plan deactivated.');
    }
}
