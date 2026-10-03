<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Services\AuditLogger;
use App\Services\PhotoRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PhotoController extends Controller
{
    /**
     * Display a paginated list of all photos across all events.
     *
     * Supports:
     *   - Filter by event (UUID or ID)
     *   - Filter by status (pending/processing/ready/failed)
     *   - Filter by owner (user ID or email via event.user)
     *
     * NEVER includes original_path, optimized_path, or thumbnail_path.
     *
     * @param  Request  $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-photos');

        $query = Photo::query()->with('event.user');

        // Filter by event (UUID or ID)
        if ($eventFilter = $request->input('event')) {
            $query->whereHas('event', function ($q) use ($eventFilter) {
                // Check if it's a UUID pattern or numeric ID
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventFilter)) {
                    $q->where('uuid', $eventFilter);
                } else {
                    $q->where('id', $eventFilter);
                }
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by owner (user ID or email)
        if ($owner = $request->input('owner')) {
            $query->whereHas('event.user', function ($q) use ($owner) {
                if (is_numeric($owner)) {
                    $q->where('id', $owner);
                } else {
                    $q->where('email', 'LIKE', "%{$owner}%");
                }
            });
        }

        $photos = $query->latest()->paginate(20)->withQueryString();

        // Transform results to match AdminPhoto type (list view)
        $photos->through(function ($photo) {
            return [
                'id'                => $photo->id,
                'uuid'              => $photo->uuid,
                'original_filename' => $photo->original_filename,
                'mime_type'         => $photo->mime_type,
                'file_size'         => $photo->file_size,
                'status'            => $photo->status,
                'event'             => [
                    'name' => $photo->event->name,
                ],
                'owner'             => [
                    'name'  => $photo->event->user->name,
                    'email' => $photo->event->user->email,
                ],
                'created_at'        => $photo->created_at->toISOString(),
            ];
        });

        return Inertia::render('Admin/Photos/Index', [
            'photos'  => $photos,
            'filters' => [
                'event'  => $request->input('event'),
                'status' => $request->input('status'),
                'owner'  => $request->input('owner'),
            ],
        ]);
    }

    /**
     * Display full detail for a single photo.
     *
     * Route model binding uses uuid.
     * NEVER includes original_path, optimized_path, or thumbnail_path (R14.5).
     *
     * @param  Photo  $photo  Route model binding on uuid
     * @return Response
     */
    public function show(Photo $photo): Response
    {
        Gate::authorize('manage-photos');

        $photo->load('event.user');

        return Inertia::render('Admin/Photos/Show', [
            'photo' => [
                'id'                => $photo->id,
                'uuid'              => $photo->uuid,
                'original_filename' => $photo->original_filename,
                'mime_type'         => $photo->mime_type,
                'file_size'         => $photo->file_size,
                'width'             => $photo->width,
                'height'            => $photo->height,
                'status'            => $photo->status,
                'event'             => [
                    'id'   => $photo->event->id,
                    'uuid' => $photo->event->uuid,
                    'name' => $photo->event->name,
                ],
                'owner'             => [
                    'id'    => $photo->event->user->id,
                    'name'  => $photo->event->user->name,
                    'email' => $photo->event->user->email,
                ],
                'created_at'        => $photo->created_at->toISOString(),
                'updated_at'        => $photo->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Soft-delete a photo and remove its storage files.
     *
     * @param  Photo  $photo
     * @param  Request  $request
     * @param  PhotoRemovalService  $service
     * @param  AuditLogger  $logger
     * @return RedirectResponse
     */
    public function destroy(
        Photo $photo,
        Request $request,
        PhotoRemovalService $service,
        AuditLogger $logger
    ): RedirectResponse {
        Gate::authorize('manage-photos');

        $logger->log(
            $request->user(),
            'photo_deleted',
            'Photo',
            $photo->id,
            "Photo '{$photo->original_filename}' removed.",
            [
                'event_uuid'        => $photo->event->uuid,
                'original_filename' => $photo->original_filename,
            ]
        );

        $service->remove($photo);

        return redirect()->back()->with('success', 'Photo deleted.');
    }
}
