<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function index(): Response
    {
        $plans = collect(config('plans', []))->map(fn ($plan) => [
            'slug'                 => $plan['slug'],
            'name'                 => $plan['name'],
            'price'                => (int) $plan['price'],
            'billing_interval'     => $plan['billing_interval'] ?? null,
            'max_active_events'    => (int) $plan['max_active_events'],
            'max_photos_per_event' => (int) $plan['max_photos_per_event'],
            'max_storage_bytes'    => (int) $plan['max_storage_bytes'],
        ])->values()->all();

        return Inertia::render('welcome', [
            'plans' => $plans,
        ]);
    }
}
