<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $plan
 * @property string $provider
 * @property string|null $provider_subscription_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property \Illuminate\Support\Carbon|null $canceled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
/*
 * Mass-assignment guard: the billing state columns below (status, provider,
 * provider_subscription_id, period timestamps, cancel flags) are ALWAYS set
 * server-side by the webhook / billing code and are NEVER populated from client
 * request input. Controllers must not pass client input (e.g. request()->all())
 * into a Subscription. Listing these columns in $fillable is safe because the
 * only writers are trusted server code paths.
 */
#[Fillable([
    'user_id',
    'plan',
    'provider',
    'provider_subscription_id',
    'status',
    'current_period_start',
    'current_period_end',
    'cancel_at_period_end',
    'canceled_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_TRIALING = 'trialing';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'canceled_at'          => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to subscriptions with an active status.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to subscriptions on a given plan slug.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeOnPlan(Builder $query, string $slug): Builder
    {
        return $query->where('plan', $slug);
    }

    /**
     * Whether this subscription currently grants access.
     *
     * True when the status is active, or when the subscription is canceled but
     * still within its paid grace period (cancel_at_period_end with a future
     * current_period_end).
     */
    public function isActiveNow(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        return $this->status === self::STATUS_CANCELED
            && $this->cancel_at_period_end
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /**
     * Whether this subscription is canceled but still within its paid period.
     */
    public function onGracePeriod(): bool
    {
        return $this->status === self::STATUS_CANCELED
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }
}
