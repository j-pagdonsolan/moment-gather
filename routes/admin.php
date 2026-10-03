<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Dashboard
        Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        // Users
        Route::get('users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/activate', [\App\Http\Controllers\Admin\UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/deactivate', [\App\Http\Controllers\Admin\UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/roles/assign', [\App\Http\Controllers\Admin\UserController::class, 'assignRole'])->name('users.roles.assign');
        Route::post('users/{user}/roles/remove', [\App\Http\Controllers\Admin\UserController::class, 'removeRole'])->name('users.roles.remove');

        // Events (uuid binding)
        Route::get('events', [\App\Http\Controllers\Admin\EventController::class, 'index'])->name('events.index');
        Route::get('events/{event:uuid}', [\App\Http\Controllers\Admin\EventController::class, 'show'])->name('events.show');
        Route::post('events/{event:uuid}/archive', [\App\Http\Controllers\Admin\EventController::class, 'archive'])->name('events.archive');
        Route::delete('events/{event:uuid}', [\App\Http\Controllers\Admin\EventController::class, 'destroy'])->name('events.destroy');

        // Photos (uuid binding)
        Route::get('photos', [\App\Http\Controllers\Admin\PhotoController::class, 'index'])->name('photos.index');
        Route::get('photos/{photo:uuid}', [\App\Http\Controllers\Admin\PhotoController::class, 'show'])->name('photos.show');
        Route::delete('photos/{photo:uuid}', [\App\Http\Controllers\Admin\PhotoController::class, 'destroy'])->name('photos.destroy');

        // Plans (slug-based, no model binding)
        Route::get('plans', [\App\Http\Controllers\Admin\PlanController::class, 'index'])->name('plans.index');
        Route::put('plans/{slug}', [\App\Http\Controllers\Admin\PlanController::class, 'update'])->name('plans.update');
        Route::post('plans/{slug}/deactivate', [\App\Http\Controllers\Admin\PlanController::class, 'deactivate'])->name('plans.deactivate');

        // Subscriptions (read-only)
        Route::get('subscriptions', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('subscriptions/{subscription}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'show'])->name('subscriptions.show');

        // Payments (read-only)
        Route::get('payments', [\App\Http\Controllers\Admin\PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [\App\Http\Controllers\Admin\PaymentController::class, 'show'])->name('payments.show');

        // Audit Logs (read-only, NO DELETE)
        Route::get('audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/{log}', [\App\Http\Controllers\Admin\AuditLogController::class, 'show'])->name('audit-logs.show');
    });
