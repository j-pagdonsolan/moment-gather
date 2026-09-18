<?php

namespace App\Billing;

use App\Models\Event;
use App\Models\Photo;
use App\Models\Subscription;
use App\Models\User;

/**
 * The single authoritative source for plan/subscription queries and all limit
 * checks (R6, R23). The backend decides every permission; the frontend never
 * decides limits or access.
 *
 * Plans are config-backed value objects ({@see Plan}); who has Pro is derived
 * from the user's `subscriptions` rows (the database is the source of truth).
 * Storage usage is computed live as `SUM(photos.file_size)` over the owner's
 * non-soft-deleted photos (Decision C) — there is no counter column. No caching.
 */
class BillingService
{
    /**
     * Resolve the plan currently in effect for the user: Pro when an active or
     * grace-period subscription grants access, otherwise Free (R6.1, R1.7).
     */
    public function currentPlan(User $user): Plan
    {
        return $this->isPro($user)
            ? Plan::fromConfig('pro')
            : Plan::fromConfig('free');
    }

    /**
     * Whether the user has Pro access right now.
     *
     * True iff ANY of the user's subscriptions grants access via
     * {@see Subscription::isActiveNow()} — i.e. status is active, or the
     * subscription is canceled but still within its paid grace period
     * (cancel_at_period_end with a future current_period_end). Expired,
     * incomplete, and failed-only subscriptions do not grant access
     * (R6.2, R6.5, R6.6, R11.3–R11.5).
     */
    public function isPro(User $user): bool
    {
        return $user->subscriptions()
            ->get()
            ->contains(fn (Subscription $subscription): bool => $subscription->isActiveNow());
    }

    /**
     * Whether the user has an active (or grace-period) subscription. For this
     * app this is the same access check as {@see isPro()} (R6.2).
     */
    public function hasActiveSubscription(User $user): bool
    {
        return $this->isPro($user);
    }

    /**
     * Whether the user may create another active event under their plan:
     * the count of active (non-soft-deleted) events is below the plan's
     * maxActiveEvents (R6.3, R7).
     */
    public function canCreateEvent(User $user): bool
    {
        return $this->activeEventCount($user) < $this->currentPlan($user)->maxActiveEvents;
    }

    /**
     * Whether an upload batch may be accepted for the given event.
     *
     * True iff both plan limits hold for the event's owner:
     *  - the event's non-deleted photo count plus the incoming count does not
     *    exceed the plan's maxPhotosPerEvent, AND
     *  - the owner's current storage usage plus the incoming bytes does not
     *    exceed the plan's maxStorageBytes.
     *
     * The batch is all-or-nothing (Decision D): callers reject the whole batch
     * when this returns false (R6.3, R8, R9).
     */
    public function canUploadPhotos(User $owner, Event $event, int $incomingCount, int $incomingBytes): bool
    {
        $plan = $this->currentPlan($owner);

        $withinPhotoLimit = ($event->photos()->count() + $incomingCount) <= $plan->maxPhotosPerEvent;
        $withinStorageLimit = ($this->storageUsedBytes($owner) + $incomingBytes) <= $plan->maxStorageBytes;

        return $withinPhotoLimit && $withinStorageLimit;
    }

    /**
     * Live storage usage in bytes for the user: the SUM of file_size over the
     * user's non-soft-deleted photos across their (non-soft-deleted) events
     * (R6.4, R9.1). Returns 0 when the user has no photos. Uses query-builder
     * parameter binding.
     */
    public function storageUsedBytes(User $user): int
    {
        return (int) Photo::query()
            ->join('events', 'events.id', '=', 'photos.event_id')
            ->where('events.user_id', $user->id)
            ->whereNull('photos.deleted_at')
            ->whereNull('events.deleted_at')
            ->sum('photos.file_size');
    }

    /**
     * The number of active (`status='active'`), non-soft-deleted events owned
     * by the user. The SoftDeletes global scope excludes trashed events (R7.5).
     */
    public function activeEventCount(User $user): int
    {
        return Event::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();
    }

    /**
     * Backend-computed usage summary for the Billing page (R14.1).
     *
     * Shape: `['events' => [used, limit], 'photos' => [used, limit],
     * 'storage' => [used, limit]]`. The photos `used` is the total non-deleted
     * photos across all of the user's events (a summary metric); the enforced
     * photo limit is per-event, surfaced here as the plan's maxPhotosPerEvent.
     *
     * @return array{
     *     events: array{used: int, limit: int},
     *     photos: array{used: int, limit: int},
     *     storage: array{used: int, limit: int}
     * }
     */
    public function usageSummary(User $user): array
    {
        $plan = $this->currentPlan($user);

        $photosUsed = (int) Photo::query()
            ->join('events', 'events.id', '=', 'photos.event_id')
            ->where('events.user_id', $user->id)
            ->whereNull('photos.deleted_at')
            ->whereNull('events.deleted_at')
            ->count('photos.id');

        return [
            'events' => [
                'used'  => $this->activeEventCount($user),
                'limit' => $plan->maxActiveEvents,
            ],
            'photos' => [
                'used'  => $photosUsed,
                'limit' => $plan->maxPhotosPerEvent,
            ],
            'storage' => [
                'used'  => $this->storageUsedBytes($user),
                'limit' => $plan->maxStorageBytes,
            ],
        ];
    }
}
