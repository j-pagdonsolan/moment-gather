<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\PublicGalleryController;
use App\Http\Controllers\PublicPhotoDownloadController;
use App\Http\Controllers\PublicPhotoUploadController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public attendee-facing event page. No authentication.
Route::get('/e/{slug}', [PublicEventController::class, 'show'])
    ->name('public.events.show');

// Public guest photo upload. No authentication. Rate-limited by IP.
Route::post('/e/{slug}/photos', [PublicPhotoUploadController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('public.events.photos.store');

// Public photo gallery. No authentication.
Route::get('/e/{slug}/gallery', [PublicGalleryController::class, 'show'])
    ->name('public.events.gallery');

// Public single-photo download. No authentication. Photo resolved by uuid in the controller.
Route::get('/e/{slug}/photos/{photo}/download', [PublicPhotoDownloadController::class, 'show'])
    ->name('public.events.photos.download');

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
