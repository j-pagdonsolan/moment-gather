# Requirements Document

## Introduction

This is Phase 8 of MomentGather. It moves image processing out of the synchronous HTTP upload request into a Laravel database-queued job. Today (Phase 7) the guest upload controller stores each original, then synchronously runs `App\Services\PhotoProcessor::process(...)` inside the request before responding. That blocks the request while large images are optimized. Phase 8 replaces synchronous processing with per-photo queued jobs so guest uploads return fast and heavy image work runs in the background.

Key facts and constraints that shape these requirements:

- **Database queue already configured**: `QUEUE_CONNECTION=database` in `.env`; the standard Laravel `jobs`, `job_batches`, and `failed_jobs` tables already exist (migration `0001_01_01_000002_create_jobs_table.php`); `config/queue.php` default is `database` and the failed driver is `database-uuids`. No new migration, no new package, and no queue config change are needed.
- **Reuse PhotoProcessor**: The queued job reuses the existing `App\Services\PhotoProcessor::process($originalPath, $eventUuid, $photoUuid): array` (returns `['optimized_path','thumbnail_path']`, throws on failure). Image-processing logic is NOT duplicated in the job.
- **Per-photo jobs**: Each uploaded photo gets its own `ProcessPhoto` job dispatched inside the upload loop, so photos succeed or fail independently.
- **Status lifecycle**: A photo starts `pending` at upload, becomes `processing` when the job begins, then `ready` on success or `failed` on error. Existing `Photo` status constants are reused; no duplicate status system is introduced.
- **Synchronous processing removed from the controller**: The upload controller no longer processes images or wraps processing in try/catch, and no longer needs the `PhotoProcessor` injection. It stores the original, creates the `Photo` as `pending`, dispatches `ProcessPhoto`, and returns a fast "uploaded and being processed" response.
- **Retry**: The job sets `tries = 3` with `backoff()` returning `[10, 30, 60]` seconds (finite, not indefinite). The `failed()` hook marks the photo `failed` and cleans up partial processed files.
- **Idempotency**: The job re-fetches the photo by id and behaves safely on re-run: returns quietly if the photo no longer exists, skips re-processing if already `ready` with processed paths, marks `failed` if the original file is missing, and overwrites output files without creating duplicate database rows.
- **Frontend copy update only**: The upload success copy is updated to reflect asynchronous processing. No realtime infrastructure (WebSockets, Pusher, Echo, polling) is added.
- **Gallery unchanged**: The gallery already shows only `ready` photos; `pending`, `processing`, and `failed` remain hidden. This behavior is confirmed and kept.
- **Test updates**: Existing Phase 7 upload tests that assume a photo is `ready` synchronously after upload must be updated to the async expectation (photo is `pending` and a `ProcessPhoto` job was dispatched). The "ready after processing" assertions move to job-execution tests. Unrelated tests are not rewritten.
- **Local development only**: Out of scope are Redis, Horizon, SQS, cloud queues, S3, DO Spaces, CDN, WebSockets, Pusher, Echo, AI, video, payments, subscriptions, deployment, and Supervisor.

## Glossary

- **Upload_Controller**: `App\Http\Controllers\PublicPhotoUploadController@store`, which accepts guest photo uploads for an active, upload-enabled event.
- **Process_Photo_Job**: The `App\Jobs\ProcessPhoto` queued job (implements `ShouldQueue`, uses `Queueable`/`SerializesModels`) that processes a single photo in the background.
- **Photo_Processor**: The existing `App\Services\PhotoProcessor` service whose `process` method generates the optimized and thumbnail WebP variants for a stored original.
- **Photo**: The `App\Models\Photo` Eloquent model. Has `status`, `original_path`, `optimized_path`, `thumbnail_path`, and a UUID; belongs to an Event.
- **Gallery_Controller**: `App\Http\Controllers\PublicGalleryController@show`, which lists photos for a public event page.
- **Photo_Uploader**: The `PhotoUploader.tsx` frontend component that displays the upload success message to guests.
- **Queue_System**: The Laravel `database` queue connection backed by the existing `jobs` table, plus the `failed_jobs` table for recording failed jobs.
- **Queue_Worker**: A `php artisan queue:work` process that consumes and executes queued jobs locally.
- **STATUS_PENDING**: The `Photo::STATUS_PENDING` value (`'pending'`), assigned at upload before processing begins.
- **STATUS_PROCESSING**: The `Photo::STATUS_PROCESSING` value (`'processing'`), assigned when the Process_Photo_Job begins work.
- **STATUS_READY**: The `Photo::STATUS_READY` value (`'ready'`), assigned after optimized and thumbnail variants are successfully generated.
- **STATUS_FAILED**: The `Photo::STATUS_FAILED` value (`'failed'`), assigned when processing fails.
- **Optimized_Variant**: The optimized WebP image at `events/{eventUuid}/optimized/{photoUuid}.webp`.
- **Thumbnail_Variant**: The thumbnail WebP image at `events/{eventUuid}/thumbnails/{photoUuid}.webp`.
- **Original_File**: The stored source image at `events/{eventUuid}/originals/{photoUuid}.{ext}` on the public disk.
- **Partial_Processed_Files**: Any Optimized_Variant or Thumbnail_Variant files written before a processing failure.

## Requirements

### Requirement 1: Fast, non-blocking guest upload

**User Story:** As an event guest, I want my photo upload to return quickly, so that I am not left waiting while large images are processed.

#### Acceptance Criteria

1. WHEN a guest submits a valid upload for an active, upload-enabled event, THE Upload_Controller SHALL store each Original_File before dispatching any Process_Photo_Job.
2. WHEN a guest submits a valid upload, THE Upload_Controller SHALL create each Photo with status STATUS_PENDING.
3. WHEN a guest submits a valid upload, THE Upload_Controller SHALL return the response without generating the Optimized_Variant or Thumbnail_Variant during the request.
4. WHEN the Upload_Controller returns after a successful upload, THE Upload_Controller SHALL include a success message indicating the photos were uploaded and are being processed.
5. THE Upload_Controller SHALL resolve the event by matching the active event slug and SHALL return a 404 response when no matching active event exists.
6. IF the resolved event has uploads disabled, THEN THE Upload_Controller SHALL return a 403 response.
7. THE Upload_Controller SHALL set each Photo event association, storage path, filename, mime type, dimensions, and status from server-determined values rather than client-supplied values.

### Requirement 2: Per-photo job dispatch

**User Story:** As a developer, I want each uploaded photo to get its own background job, so that photos are processed and can succeed or fail independently.

#### Acceptance Criteria

1. WHEN a guest submits an upload containing multiple photos, THE Upload_Controller SHALL dispatch one Process_Photo_Job for each uploaded Photo.
2. WHEN a Process_Photo_Job is dispatched, THE Upload_Controller SHALL enqueue the job on the Queue_System.
3. THE Process_Photo_Job SHALL reference exactly one Photo per job instance.

### Requirement 3: Background photo processing

**User Story:** As an event owner, I want uploaded photos to be optimized in the background, so that the gallery shows properly processed images without slowing uploads.

#### Acceptance Criteria

1. WHEN a Process_Photo_Job begins execution, THE Process_Photo_Job SHALL set the referenced Photo status to STATUS_PROCESSING.
2. WHEN a Process_Photo_Job processes a Photo whose Original_File exists, THE Process_Photo_Job SHALL invoke Photo_Processor to generate the Optimized_Variant and the Thumbnail_Variant.
3. WHEN Photo_Processor returns the generated paths, THE Process_Photo_Job SHALL store the optimized path and thumbnail path on the Photo and set status to STATUS_READY.
4. THE Process_Photo_Job SHALL set the Photo status to STATUS_READY only after both the Optimized_Variant and the Thumbnail_Variant are successfully generated.
5. THE Process_Photo_Job SHALL reuse Photo_Processor for image processing rather than duplicating image-processing logic.

### Requirement 4: Job failure handling

**User Story:** As an event owner, I want failed photo processing to be recorded and hidden, so that guests never see broken images and I can investigate failures.

#### Acceptance Criteria

1. WHEN Photo_Processor throws during a Process_Photo_Job, THE Process_Photo_Job SHALL allow the exception to propagate so the Queue_System records the failure.
2. WHEN a Process_Photo_Job exhausts its retry attempts, THE Process_Photo_Job failed hook SHALL set the referenced Photo status to STATUS_FAILED.
3. WHEN a Process_Photo_Job fails, THE Process_Photo_Job SHALL delete any Partial_Processed_Files associated with the Photo.
4. WHEN a Process_Photo_Job fails, THE Process_Photo_Job SHALL log the failure without exposing exception details to guests.
5. WHEN a Process_Photo_Job fails after exhausting retries, THE Queue_System SHALL record an entry in the failed_jobs table.

### Requirement 5: Retry behavior

**User Story:** As a developer, I want transient processing failures to be retried a limited number of times, so that temporary errors recover without retrying forever.

#### Acceptance Criteria

1. THE Process_Photo_Job SHALL define a maximum attempt count of 3.
2. THE Process_Photo_Job SHALL define a retry backoff sequence of 10, 30, and 60 seconds.
3. WHEN a Process_Photo_Job reaches its maximum attempt count without success, THE Queue_System SHALL stop retrying the job.

### Requirement 6: Job idempotency

**User Story:** As a developer, I want the processing job to be safe to run more than once, so that retries and re-dispatches do not corrupt data or create duplicates.

#### Acceptance Criteria

1. WHEN a Process_Photo_Job executes for a Photo that no longer exists, THE Process_Photo_Job SHALL return without error.
2. WHEN a Process_Photo_Job executes for a Photo whose status is already STATUS_READY with stored optimized and thumbnail paths, THE Process_Photo_Job SHALL skip re-processing.
3. IF a Process_Photo_Job executes for a Photo whose Original_File is missing, THEN THE Process_Photo_Job SHALL set the Photo status to STATUS_FAILED without throwing an unhandled crash.
4. WHEN a Process_Photo_Job re-processes a Photo, THE Process_Photo_Job SHALL overwrite the Optimized_Variant and Thumbnail_Variant files in place.
5. WHEN a Process_Photo_Job re-processes a Photo, THE Process_Photo_Job SHALL update the existing Photo record rather than creating a new Photo record.

### Requirement 7: Gallery shows only ready photos

**User Story:** As an event guest, I want to see only fully processed photos in the gallery, so that I never see broken or partial images.

#### Acceptance Criteria

1. WHEN the Gallery_Controller lists photos for an event, THE Gallery_Controller SHALL include only photos with status STATUS_READY.
2. WHEN the Gallery_Controller lists photos for an event, THE Gallery_Controller SHALL exclude photos with status STATUS_PENDING, STATUS_PROCESSING, or STATUS_FAILED.

### Requirement 8: Guest upload user experience

**User Story:** As an event guest, I want clear feedback that my upload succeeded and is being processed, so that I understand my photos may take a moment to appear.

#### Acceptance Criteria

1. WHEN an upload succeeds, THE Photo_Uploader SHALL display a success message indicating the photos were uploaded and are being processed.
2. THE Photo_Uploader SHALL convey upload success without using WebSockets, polling, or broadcasting.

### Requirement 9: Local queue operation and configuration

**User Story:** As a developer, I want to run the queue locally with standard Laravel tooling, so that I can process and inspect jobs without extra infrastructure.

#### Acceptance Criteria

1. THE Queue_System SHALL use the Laravel database queue connection backed by the existing jobs table.
2. THE Queue_System SHALL record failed jobs in the existing failed_jobs table using the configured database-uuids failed driver.
3. WHILE no Queue_Worker is running, THE Queue_System SHALL keep dispatched jobs enqueued so that affected photos remain in STATUS_PENDING or STATUS_PROCESSING until a Queue_Worker runs.
4. WHERE local queue operation is documented, THE documentation SHALL describe running the Queue_Worker with `php artisan queue:work`, inspecting failures with `php artisan queue:failed`, and retrying failures with `php artisan queue:retry all`.
5. THE feature SHALL operate using the existing jobs and failed_jobs tables without adding a new migration, package, or queue configuration change.

### Requirement 10: Test coverage for async behavior

**User Story:** As a developer, I want tests that verify the new asynchronous behavior, so that regressions are caught and Phase 1–7 behavior stays intact.

#### Acceptance Criteria

1. THE test suite SHALL verify that a successful upload creates a Photo with status STATUS_PENDING and dispatches a Process_Photo_Job.
2. THE test suite SHALL verify that immediately after a successful upload the Photo has a null optimized path and status STATUS_PENDING, confirming no synchronous processing occurred.
3. THE test suite SHALL verify that an upload of multiple photos dispatches one Process_Photo_Job per uploaded photo.
4. THE test suite SHALL verify that executing a Process_Photo_Job for a valid photo generates the Optimized_Variant and Thumbnail_Variant and sets status to STATUS_READY.
5. THE test suite SHALL verify that a Process_Photo_Job failure sets the Photo status to STATUS_FAILED and removes Partial_Processed_Files.
6. THE test suite SHALL verify that the Process_Photo_Job defines a maximum attempt count of 3 and a backoff sequence of 10, 30, and 60 seconds.
7. THE test suite SHALL verify that when multiple Process_Photo_Job instances run and one fails, the remaining photos still reach STATUS_READY.
8. THE test suite SHALL verify that the Gallery_Controller returns STATUS_READY photos and excludes STATUS_PROCESSING and STATUS_FAILED photos.
9. THE test suite SHALL update the Phase 7 upload tests that assumed synchronous STATUS_READY to assert the asynchronous behavior in which the photo is STATUS_PENDING and a Process_Photo_Job is dispatched.
10. THE test suite SHALL keep the existing Phase 1 through Phase 7 tests passing except for the Phase 7 upload tests updated to the asynchronous expectation.
