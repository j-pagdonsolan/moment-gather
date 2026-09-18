<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $subscription_id
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
// All fillable attributes are set server-side only (from the payment provider
// webhook / BillingService). Client/request input is NEVER mass-assigned here.
// $guarded=[] is deliberately avoided to keep the write surface explicit.
#[Fillable(['user_id', 'subscription_id', 'provider', 'provider_payment_id', 'amount', 'currency', 'status', 'paid_at', 'metadata'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'   => 'integer',
            'paid_at'  => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
