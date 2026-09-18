<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal idempotency ledger for provider webhook events.
 *
 * @property int $id
 * @property string $provider_event_id
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[Fillable(['provider_event_id', 'type'])]
class WebhookEvent extends Model
{
}
