<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No update() or delete() calls are ever issued on this model in application code.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string $description
 * @property array|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['user_id', 'action', 'target_type', 'target_id', 'description', 'metadata', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;
    /**
     * Disable automatic timestamp management.
     * created_at is set manually on insert; there is no updated_at column.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed the action.
     * withDefault() ensures that a null user_id does not cause errors.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault();
    }
}
