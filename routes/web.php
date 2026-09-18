<?php

use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\CancelSubscriptionController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\FakeCheckoutController;
use App\Http\Controllers\Billing\WebhookController;
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
    ->middleware('throttle:browse')
    ->name('public.events.show');

// Public guest photo upload. No authentication. Rate-limited by IP.
Route::post('/e/{slug}/photos', [PublicPhotoUploadController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('public.events.photos.store');

// Public photo gallery. No authentication.
Route::get('/e/{slug}/gallery', [PublicGalleryController::class, 'show'])
    ->middleware('throttle:browse')
    ->name('public.events.gallery');

// Public single-photo download. No authentication. Photo resolved by uuid in the controller.
Route::get('/e/{slug}/photos/{photo}/download', [PublicPhotoDownloadController::class, 'show'])
    ->middleware('throttle:browse')
    ->name('public.events.photos.download');

// Public payment-provider webhook. No authentication and CSRF-excluded (see bootstrap/app.php).
// Authenticity is enforced by verifying the provider signature inside the controller.
Route::post('billing/webhook', [WebhookController::class, 'handle'])->name('billing.webhook');

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

    // Billing (authenticated organizers). Backend is authoritative for all plan/limit state.
    Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('billing/checkout', [CheckoutController::class, 'store'])->name('billing.checkout');
    Route::get('billing/checkout/success', [CheckoutController::class, 'success'])->name('billing.checkout.success');
    Route::get('billing/checkout/cancel', [CheckoutController::class, 'cancel'])->name('billing.checkout.cancel');
    Route::post('billing/cancel', [CancelSubscriptionController::class, 'store'])->name('billing.cancel');
    Route::get('billing/fake-checkout', [FakeCheckoutController::class, 'show'])->name('billing.fake-checkout');
    Route::post('billing/fake-checkout/complete', [FakeCheckoutController::class, 'complete'])->name('billing.fake-checkout.complete');
});

require __DIR__.'/settings.php';
