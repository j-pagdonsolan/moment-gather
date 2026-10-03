<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    /**
     * Append an immutable audit log entry.
     *
     * IMPORTANT — metadata must NEVER contain:
     *   - passwords or password hashes
     *   - secrets (secret_key, webhook_secret, two_factor_secret, etc.)
     *   - raw storage paths (original_path, optimized_path, thumbnail_path)
     *   - provider_payment_id or provider_subscription_id
     *   - remember_token or two_factor_recovery_codes
     *
     * Audited action strings:
     *   user_activated, user_deactivated, role_assigned, role_removed,
     *   event_archived, event_deleted, photo_deleted,
     *   plan_limit_updated, plan_deactivated
     *
     * @param  User|null  $actor       The authenticated user performing the action (null for system/CLI).
     * @param  string     $action      A short snake_case action identifier (e.g. 'user_activated').
     * @param  string     $targetType  The model class short name or table name (e.g. 'User', 'Event').
     * @param  int|null   $targetId    The primary key of the affected record, or null.
     * @param  string     $description Human-readable description of what happened.
     * @param  array      $metadata    Additional context. Must not contain secrets or raw paths.
     * @return AuditLog               The newly created audit log record.
     */
    public function log(
        ?User $actor,
        string $action,
        string $targetType,
        ?int $targetId,
        string $description,
        array $metadata = [],
    ): AuditLog {
        $isConsole = app()->runningInConsole();

        return AuditLog::create([
            'user_id'     => $actor?->id,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'description' => $description,
            'metadata'    => $metadata,
            'ip_address'  => $isConsole ? null : request()->ip(),
            'user_agent'  => $isConsole ? null : request()->userAgent(),
            'created_at'  => now(),
        ]);
    }
}
