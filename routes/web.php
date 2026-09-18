<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PublicEventController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public attendee-facing event page. No authentication.
Route::get('/e/{slug}', [PublicEventController::class, 'show'])
    ->name('public.events.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('events', EventController::class)
        ->parameters(['events' => 'event'])
        ->except(['index'])
        ->names([
            'create'  => 'events.create',
            'store'   => 'events.store',
            'show'    => 'events.show',
            'edit'    => 'events.edit',
            'update'  => 'events.update',
            'destroy' => 'events.destroy',
        ]);

    Route::get('events', [EventController::class, 'index'])->name('events.index');
});

require __DIR__.'/settings.php';
