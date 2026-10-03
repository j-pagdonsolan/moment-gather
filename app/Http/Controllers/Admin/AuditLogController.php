<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Display a paginated list of audit logs with filters.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('view-audit-logs');

        $query = AuditLog::query()->with('actor');

        // Filter by action (exact match)
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter by actor (user ID or email, or system if user_id is null)
        if ($request->filled('actor')) {
            $actorValue = $request->actor;
            
            if ($actorValue === 'system' || $actorValue === 'System') {
                // System actions (no user_id)
                $query->whereNull('user_id');
            } elseif (is_numeric($actorValue)) {
                // User ID
                $query->where('user_id', $actorValue);
            } else {
                // Email search
                $query->whereHas('actor', function ($q) use ($actorValue) {
                    $q->where('email', 'LIKE', '%' . $actorValue . '%');
                });
            }
        }

        // Filter by date range
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [$request->from, $request->to]);
        } elseif ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        } elseif ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Filter by target_type
        if ($request->filled('target_type')) {
            $query->where('target_type', $request->target_type);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(25);

        // Transform to R19.3 format
        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'actor' => $log->actor->exists 
                    ? [
                        'name' => $log->actor->name,
                        'email' => $log->actor->email,
                    ]
                    : 'System',
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toISOString(),
                // Raw metadata excluded from list view
            ];
        });

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => [
                'action' => $request->action,
                'actor' => $request->actor,
                'from' => $request->from,
                'to' => $request->to,
                'target_type' => $request->target_type,
            ],
        ]);
    }

    /**
     * Display audit log detail with metadata.
     */
    public function show(AuditLog $log): Response
    {
        Gate::authorize('view-audit-logs');

        $log->load('actor');

        // Strip sensitive keys from metadata
        $metadata = $log->metadata ?? [];
        $sensitiveKeys = [
            'password',
            'secret_key',
            'webhook_secret',
            'two_factor_secret',
            'provider_payment_id',
            'provider_subscription_id',
        ];

        foreach ($sensitiveKeys as $key) {
            unset($metadata[$key]);
        }

        $logData = [
            'id' => $log->id,
            'actor' => $log->actor->exists
                ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ]
                : null,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'description' => $log->description,
            'metadata' => $metadata,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at->toISOString(),
        ];

        return Inertia::render('Admin/AuditLogs/Show', [
            'log' => $logData,
        ]);
    }
}
