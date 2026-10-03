<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property int|null $max_active_events
 * @property int|null $max_photos_per_event
 * @property int|null $max_storage_bytes
 * @property int|null $price
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[Fillable(['slug', 'max_active_events', 'max_photos_per_event', 'max_storage_bytes', 'price', 'is_active'])]
class PlanOverride extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_active_events'    => 'integer',
            'max_photos_per_event' => 'integer',
            'max_storage_bytes'    => 'integer',
            'price'                => 'integer',
            'is_active'            => 'boolean',
        ];
    }
}
