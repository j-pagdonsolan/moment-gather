<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingService;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController
{
    public function __construct(
        private BillingService $billing,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Display a paginated list of users with search and filtering.
     *
     * Each record includes: id, name, email, is_active, role names, plan,
     * event count, created_at. NEVER exposes password/secrets.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-users');

        $query = User::query()->with('roles');

        // Search by name or email (case-insensitive)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($roleSlug = $request->input('role')) {
            $query->whereHas('roles', function ($q) use ($roleSlug) {
                $q->where('name', $roleSlug);
            });
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $users = $query->withCount('events')
            ->orderByDesc('created_at')
            ->paginate(15);

        // Transform the paginated collection
        $users->getCollection()->transform(function (User $user) {
            return [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'is_active'   => $user->is_active,
                'roles'       => $user->roles->pluck('name')->toArray(),
                'plan'        => $this->billing->isPro($user) ? 'pro' : 'free',
                'event_count' => $user->events_count,
                'created_at'  => $user->created_at->toISOString(),
            ];
        });

        return Inertia::render('Admin/Users/Index', [
            'users'   => $users,
            'filters' => [
                'search'    => $request->input('search'),
                'role'      => $request->input('role'),
                'is_active' => $request->input('is_active'),
            ],
        ]);
    }

    /**
     * Display the full detail for a user.
     *
     * Includes: account info, roles, is_active status, current plan, event count,
     * photo count, storage used, 2FA status. NEVER exposes R8.6 forbidden fields.
     */
    public function show(User $user): Response
    {
        Gate::authorize('manage-users');

        $user->load('roles', 'subscriptions');

        $photoCount = $user->events()
            ->withCount('photos')
            ->get()
            ->sum('photos_count');

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'email_verified_at'  => $user->email_verified_at?->toISOString(),
                'is_active'          => $user->is_active,
                'roles'              => $user->roles->pluck('name')->toArray(),
                'plan'               => $this->billing->isPro($user) ? 'pro' : 'free',
                'has_active_subscription' => $this->billing->hasActiveSubscription($user),
                'event_count'        => $user->events()->count(),
                'photo_count'        => $photoCount,
                'storage_used_bytes' => $this->billing->storageUsedBytes($user),
                'two_fa_enabled'     => $user->two_factor_confirmed_at !== null,
                'created_at'         => $user->created_at->toISOString(),
                'updated_at'         => $user->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Activate a user account (set is_active to true).
     */
    public function activate(User $user, Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');

        $user->is_active = true;
        $user->save();

        $this->auditLogger->log(
            $request->user(),
            'user_activated',
            'User',
            $user->id,
            "User {$user->email} activated.",
            [],
        );

        return redirect()->back()->with('toast', [
            'type'    => 'success',
            'message' => 'User activated.',
        ]);
    }

    /**
     * Deactivate a user account (set is_active to false).
     *
     * Guards: Cannot deactivate the last active Super Admin.
     */
    public function deactivate(User $user, Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');

        // Guard: prevent deactivation of the last active Super Admin
        if ($user->isAdmin()) {
            $activeSuperAdminCount = User::whereHas('roles', function ($query) {
                $query->where('name', 'super_admin');
            })->where('is_active', true)->count();

            if ($activeSuperAdminCount === 1) {
                abort(422, 'Cannot deactivate the last active Super Admin.');
            }
        }

        $user->is_active = false;
        $user->save();

        $this->auditLogger->log(
            $request->user(),
            'user_deactivated',
            'User',
            $user->id,
            "User {$user->email} deactivated.",
            [],
        );

        return redirect()->back()->with('toast', [
            'type'    => 'success',
            'message' => 'User deactivated.',
        ]);
    }

    /**
     * Assign a role to a user.
     *
     * Guards: Cannot modify your own role.
     */
    public function assignRole(User $user, Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        // Guard: cannot modify your own role
        if ($user->id === $request->user()->id) {
            abort(422, 'Cannot modify your own role.');
        }

        $role = Role::findOrFail($validated['role_id']);

        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->auditLogger->log(
            $request->user(),
            'role_assigned',
            'User',
            $user->id,
            "Role '{$role->name}' assigned to {$user->email}.",
            ['role_name' => $role->name],
        );

        return redirect()->back()->with('toast', [
            'type'    => 'success',
            'message' => 'Role assigned.',
        ]);
    }

    /**
     * Remove a role from a user.
     *
     * Guards: Cannot remove the last Super Admin role.
     */
    public function removeRole(User $user, Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $role = Role::findOrFail($validated['role_id']);

        // Guard: prevent removing the last super_admin role
        if ($role->name === 'super_admin') {
            $superAdminCount = User::whereHas('roles', function ($query) {
                $query->where('name', 'super_admin');
            })->count();

            if ($superAdminCount === 1) {
                abort(422, 'Cannot remove the last Super Admin role.');
            }
        }

        $user->roles()->detach($role->id);

        $this->auditLogger->log(
            $request->user(),
            'role_removed',
            'User',
            $user->id,
            "Role '{$role->name}' removed from {$user->email}.",
            ['role_name' => $role->name],
        );

        return redirect()->back()->with('toast', [
            'type'    => 'success',
            'message' => 'Role removed.',
        ]);
    }
}
