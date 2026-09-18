# Requirements Document

## Introduction

This feature is **Phase 5** of MomentGather: it lets event attendees upload photos to an event **without any account, login, registration, email, or password**. Phases 1–4 (authentication, dashboard, Event CRUD, ownership/authorization, the public event page at `/e/{slug}`, and QR code generation) are already implemented and MUST NOT be recreated or altered beyond the narrowly scoped additions listed here.

The attendee flow is: scan the event QR code → open the public event page → select photos from the device → upload → receive a success confirmation.

### Decided technical choices

Two implementation decisions are fixed by the product owner and the requirements are written around them:

1. **Image dimensions** are extracted with PHP's built-in `getimagesize()` function (no image-processing package such as `intervention/image`). This supports JPG, JPEG, PNG, and WEBP.
2. **Storage** uses the existing Laravel `public` disk (`config/filesystems.php` → `disks.public` → `storage/app/public`, web-servable via `php artisan storage:link`). Files are stored at the server-built path `events/{event_uuid}/originals/{photo_uuid}.{ext}`. There is no cloud storage and no new disk.

### Scope boundaries (strict)

This phase is the **basic upload flow only**. The following are explicitly **out of scope**: image processing/resizing, WebP conversion, thumbnail generation, queues/jobs (no `ProcessPhotoJob`), Redis, S3/DigitalOcean Spaces/CDN, AI, video, a gallery UI, and any organizer photo-management UI. The original uploaded image is stored unchanged.

### Server-authoritative posture

The system MUST treat all security-relevant values as server-determined and MUST NOT trust client-supplied values:

- The Event is resolved **from the URL slug**, never from a client-supplied event id.
- The stored filename and storage path are **server-generated** (`{photo_uuid}.{ext}`), never taken from the client.
- The MIME type is **server-detected**, never taken from the browser-reported type.
- The photo `status` is **forced to `ready`** by the server on successful upload.
- Upload eligibility (event active, not deleted, `upload_enabled = true`) is enforced **server-side** before any file is accepted.

### Delivered artifacts (tight scope)

One migration (`photos` table), one `Photo` model, an `Event::photos()` relationship, one Form Request, one controller action for upload, one public route protected by throttling, one new React component (`PhotoUploader.tsx`) plus an edit to `Public/Event.tsx`, and feature tests. No gallery, no organizer UI.

## Glossary

- **Attendee (Guest)**: An unauthenticated visitor of the public event page who uploads photos. Has no account, session, or credentials.
- **Organizer**: The authenticated user who owns the Event. Not involved in this phase beyond owning the Event that photos associate with.
- **Event**: An existing Eloquent model (`app/Models/Event.php`) with `uuid`, `slug` (unique), `status` (`active` | `archived`; `draft` is a non-active value used by tests), and `upload_enabled` (boolean). Uses SoftDeletes; route key is `uuid`.
- **Public_Event_Page**: The attendee-facing Inertia page `Public/Event` served at `GET /e/{slug}`, rendered by `PublicEventController@show`.
- **Photo**: The new Eloquent model and `photos` database row representing one uploaded image, associated with exactly one Event.
- **Photo_Uploader**: The new React component `resources/js/components/PhotoUploader.tsx` embedded in `Public/Event.tsx` that handles file selection, preview, removal, upload, and progress.
- **Upload_Endpoint**: The public route `POST /e/{slug}/photos` (name `public.events.photos.store`) handled by the upload controller action, outside the auth group, protected by a throttle limiter.
- **Upload_Validator**: The Laravel Form Request that validates the incoming upload request server-side.
- **Public_Disk**: The Laravel filesystem disk named `public` (`storage/app/public`), accessed via `Storage::disk('public')`.
- **Storage_Path**: The server-built path for a stored file: `events/{event_uuid}/originals/{photo_uuid}.{ext}`.
- **Active_Event**: An Event where `status = 'active'` AND `deleted_at IS NULL`.
- **Uploadable_Event**: An Active_Event where `upload_enabled = true`.
- **Supported_Format**: An image whose validated type is one of JPG, JPEG, PNG, or WEBP.
- **Max_Photo_Size**: The maximum accepted size per photo: 20 MB (20480 KB).
- **Max_Photos_Per_Request**: The maximum number of photos accepted in one upload request: 20.
- **Upload_Rate_Limit**: The initial rate limit for the Upload_Endpoint: 10 upload requests per minute per client IP address.

## Requirements

### Requirement 1: Guest photo upload without authentication

**User Story:** As an attendee, I want to upload photos to an event without creating an account or logging in, so that I can share moments quickly after scanning the QR code.

#### Acceptance Criteria

1. THE Upload_Endpoint SHALL accept requests from unauthenticated clients.
2. THE Upload_Endpoint SHALL be registered outside the authentication middleware group.
3. WHEN an Attendee submits an upload request without any credentials, session, email, or password, THE Upload_Endpoint SHALL process the request without requiring authentication.
4. THE Upload_Endpoint SHALL NOT require any attendee-identifying information as a condition of accepting an upload.

### Requirement 2: Photos database table

**User Story:** As a developer, I want a `photos` table that mirrors existing conventions, so that uploaded photos are persisted consistently and the next phase can query them.

#### Acceptance Criteria

1. THE photos migration SHALL create a `photos` table with an auto-incrementing primary key `id`.
2. THE photos migration SHALL create an `event_id` column as a foreign key referencing `events.id` that cascades on delete.
3. THE photos migration SHALL create a `uuid` column of type char(36) with a unique constraint.
4. THE photos migration SHALL create the columns `original_filename` (string), `original_path` (string), `mime_type` (string), `file_size` (unsigned integer, bytes), `width` (nullable integer), `height` (nullable integer), and `status` (string).
5. THE photos migration SHALL create timestamp columns and a soft-delete `deleted_at` column.

### Requirement 3: Photo model

**User Story:** As a developer, I want a `Photo` model with proper casts, relationships, and UUID generation, so that photos are safe to reference publicly and internal ids are never exposed.

#### Acceptance Criteria

1. THE Photo model SHALL declare fillable attributes for the writable photo columns.
2. THE Photo model SHALL cast `file_size`, `width`, and `height` to integer and `deleted_at` to datetime.
3. THE Photo model SHALL define a `belongsTo` relationship to the Event model.
4. WHEN a Photo record is being created without a `uuid` value, THE Photo model SHALL generate a UUID before persisting.
5. THE Photo model SHALL use SoftDeletes.
6. THE Event model SHALL define a `hasMany` relationship named `photos` to the Photo model.

### Requirement 4: Photo status lifecycle for this phase

**User Story:** As a developer, I want a clear status value on each photo, so that the next phase can distinguish successfully uploaded photos.

#### Acceptance Criteria

1. WHEN a photo file is stored and its record is created successfully, THE upload controller action SHALL set the photo `status` to `ready`.
2. THE upload controller action SHALL set the photo `status` value from the server and SHALL ignore any client-supplied status value.
3. THE Photo model SHALL support the status values `pending`, `processing`, `ready`, `failed`, and `deleted` for use by current and future phases.

### Requirement 5: Public upload route

**User Story:** As an attendee, I want a public URL to post my photos to, so that my selected files reach the correct event.

#### Acceptance Criteria

1. THE Upload_Endpoint SHALL be registered as `POST /e/{slug}/photos` with the route name `public.events.photos.store`.
2. WHEN an upload request is received, THE upload controller action SHALL resolve the Event by the `{slug}` path segment.
3. THE upload controller action SHALL derive the target `event_id` from the slug-resolved Event and SHALL ignore any event identifier supplied in the request body.

### Requirement 6: Upload request validation

**User Story:** As an attendee, I want the server to validate my files, so that only genuine supported images within limits are accepted and I get clear feedback otherwise.

#### Acceptance Criteria

1. THE Upload_Validator SHALL require the `photos` field to be an array of files.
2. THE Upload_Validator SHALL accept files whose validated format is a Supported_Format (JPG, JPEG, PNG, or WEBP) and SHALL reject files of any other format.
3. IF a request contains more than Max_Photos_Per_Request files, THEN THE Upload_Validator SHALL reject the request.
4. IF any file exceeds Max_Photo_Size, THEN THE Upload_Validator SHALL reject the request.
5. THE Upload_Validator SHALL validate each file as a valid image using server-side inspection of the file contents rather than the browser-reported MIME type.
6. IF a file is not a valid, decodable image, THEN THE Upload_Validator SHALL reject the request.
7. WHEN validation fails, THE Upload_Validator SHALL return validation messages without exposing server paths, stack traces, database errors, or internal exception details.

### Requirement 7: Server-side upload eligibility

**User Story:** As an organizer, I want uploads restricted to my active, upload-enabled events, so that photos are only collected when I intend them to be.

#### Acceptance Criteria

1. WHEN an upload request targets a slug that resolves to an Active_Event with `upload_enabled = true`, THE upload controller action SHALL proceed to accept the upload.
2. IF an upload request targets a slug that does not resolve to an Active_Event, THEN THE upload controller action SHALL respond with HTTP 404.
3. WHILE a resolved Active_Event has `upload_enabled = false`, THE upload controller action SHALL reject the upload with the message "Photo uploads are currently closed." and an HTTP 403 status.
4. THE upload controller action SHALL enforce all eligibility checks on the server before accepting any file.

### Requirement 8: Server-controlled file storage

**User Story:** As a security-conscious developer, I want the server to control where and how files are stored, so that clients cannot control filenames or paths and no orphaned files remain.

#### Acceptance Criteria

1. THE upload controller action SHALL store each accepted file on the Public_Disk via the Laravel Storage facade.
2. THE upload controller action SHALL store each file at the Storage_Path `events/{event_uuid}/originals/{photo_uuid}.{ext}` where `{ext}` is derived from the validated file.
3. THE upload controller action SHALL generate the stored filename from the photo UUID and SHALL NOT use the client-supplied original filename as the stored filename.
4. THE upload controller action SHALL NOT use any client-supplied storage path or absolute filesystem path.

### Requirement 9: Persist photo metadata

**User Story:** As a developer, I want each uploaded photo recorded with accurate metadata, so that photos display correctly and can be queried next phase.

#### Acceptance Criteria

1. WHEN a file is stored successfully, THE upload controller action SHALL create a Photo record with `event_id` (from slug resolution), a generated `uuid`, `original_filename` (the client's original name, preserved for display only), `original_path` (the Storage_Path), server-detected `mime_type`, `file_size`, `width`, `height`, and `status = 'ready'`.
2. THE upload controller action SHALL set `mime_type` from server-side inspection of the stored file.
3. IF creating the Photo record fails after the file has been stored, THEN THE upload controller action SHALL delete the stored file so that no orphaned file remains.
4. IF storing the file fails, THEN THE upload controller action SHALL NOT create a Photo record for that file.

### Requirement 10: Extract image dimensions

**User Story:** As a developer, I want the pixel dimensions of each photo recorded, so that later phases can lay out images without reprocessing them.

#### Acceptance Criteria

1. WHEN a photo is stored, THE upload controller action SHALL extract the image width and height using `getimagesize()`.
2. THE upload controller action SHALL store the extracted width and height on the Photo record.
3. THE upload controller action SHALL store the original image unchanged and SHALL NOT resize or modify the image file.

### Requirement 11: Photo upload UI

**User Story:** As an attendee, I want a simple mobile-first uploader on the event page, so that I can select, review, and upload photos from my phone.

#### Acceptance Criteria

1. THE Photo_Uploader SHALL allow the Attendee to select multiple photos from the device.
2. THE Photo_Uploader SHALL display the list of currently selected files.
3. WHEN the Attendee removes a selected file before uploading, THE Photo_Uploader SHALL exclude that file from the upload.
4. THE Photo_Uploader SHALL replace the placeholder Upload Photos button area on the Public_Event_Page.
5. WHILE the resolved event payload has `upload_enabled = false`, THE Public_Event_Page SHALL display "Photo uploads are currently closed." instead of the uploader controls.

### Requirement 12: Upload user experience copy and flow

**User Story:** As an attendee, I want clear guidance and confirmation, so that I know what to do and that my upload succeeded.

#### Acceptance Criteria

1. THE Photo_Uploader SHALL display the heading "Share Your Moments".
2. THE Photo_Uploader SHALL display the instructional text "Select photos from your device and add them to this event.".
3. THE Photo_Uploader SHALL present a control labeled "Select Photos" and, when files are selected, a control labeled "Upload Photos".
4. WHEN an upload completes successfully, THE Photo_Uploader SHALL display the message "Your photos have been added to the event!".
5. THE Photo_Uploader SHALL NOT require the Attendee to enter any personal or contact information.

### Requirement 13: Client-side file preview and removal

**User Story:** As an attendee, I want to preview and prune my selected photos before uploading, so that I only upload the images I intend to.

#### Acceptance Criteria

1. WHEN the Attendee selects image files, THE Photo_Uploader SHALL display a preview thumbnail for each selected file using a client-side object URL.
2. THE Photo_Uploader SHALL allow the Attendee to remove any individual selected photo before upload.
3. WHEN the Attendee removes a selected photo, THE Photo_Uploader SHALL not upload that photo and SHALL not create any server record for it.

### Requirement 14: Upload progress and duplicate prevention

**User Story:** As an attendee, I want visible progress and protection against double-submitting, so that I trust the upload is working and I do not accidentally upload twice.

#### Acceptance Criteria

1. WHILE an upload request is in progress, THE Photo_Uploader SHALL display upload progress as a percentage using the Inertia useForm progress callback.
2. WHILE an upload request is in progress, THE Photo_Uploader SHALL disable the "Upload Photos" control to prevent duplicate submissions.
3. WHEN uploading multiple files in one request, THE Photo_Uploader SHALL indicate that the upload is in progress for the whole request.

### Requirement 15: Friendly error handling

**User Story:** As an attendee, I want understandable error messages, so that I can correct problems without seeing technical internals.

#### Acceptance Criteria

1. IF the server rejects a file for invalid type, excessive size, too many files, or invalid image, THEN THE Photo_Uploader SHALL display a friendly message describing the problem.
2. IF the upload is rejected because uploads are disabled or the event is unavailable, THEN THE Photo_Uploader SHALL display a friendly message describing the condition.
3. IF a storage failure or network failure occurs, THEN THE Photo_Uploader SHALL display a friendly failure message.
4. THE Upload_Endpoint SHALL NOT expose stack traces, server filesystem paths, database errors, or internal exception details in any response to the Attendee.

### Requirement 16: Rate limiting

**User Story:** As an operator, I want basic rate limiting on the public upload endpoint, so that casual abuse is limited without complex infrastructure.

#### Acceptance Criteria

1. THE Upload_Endpoint SHALL apply an Upload_Rate_Limit of 10 upload requests per minute per client IP address.
2. IF a client exceeds the Upload_Rate_Limit, THEN THE Upload_Endpoint SHALL respond with HTTP 429.
3. THE Upload_Rate_Limit value SHALL be defined in a configurable location (a named limiter or configuration value) rather than hard-coded inline at the route only.

### Requirement 17: Server-authoritative security values

**User Story:** As a security-conscious developer, I want the server to determine every trust-sensitive value, so that malicious clients cannot manipulate storage, association, or state.

#### Acceptance Criteria

1. THE upload controller action SHALL derive the associated `event_id` solely from the slug-resolved Event and SHALL ignore any event identifier in the request body.
2. THE upload controller action SHALL determine the stored file path and filename on the server and SHALL ignore any client-supplied path or filename for storage purposes.
3. THE upload controller action SHALL determine `mime_type` from server-side inspection and SHALL ignore the browser-reported MIME type.
4. THE upload controller action SHALL set `status` to `ready` on the server and SHALL ignore any client-supplied status.

### Requirement 18: Gallery-readiness (data only)

**User Story:** As a developer, I want the data shaped so the next phase can add a gallery easily, so that no rework is needed later.

#### Acceptance Criteria

1. WHEN a photo upload succeeds, THE upload controller action SHALL persist the photo with `status = 'ready'` so a future gallery can select ready photos.
2. THE Event `photos` relationship SHALL associate every stored photo with the correct Event.

### Requirement 19: Feature test coverage

**User Story:** As a developer, I want feature tests covering the upload behavior and its guardrails, so that the flow and its security properties stay correct.

#### Acceptance Criteria

1. THE feature test suite SHALL verify that a guest can upload without authentication.
2. THE feature test suite SHALL verify that JPG, JPEG, PNG, and WEBP files are accepted and that an unsupported file, a file over Max_Photo_Size, a request over Max_Photos_Per_Request, and an invalid image are each rejected.
3. THE feature test suite SHALL verify that uploads to an Uploadable_Event succeed and that uploads to draft, archived, or soft-deleted events respond with HTTP 404.
4. THE feature test suite SHALL verify that an upload to an event with `upload_enabled = false` is rejected.
5. THE feature test suite SHALL verify that a Photo record is created, belongs to the correct Event, has a generated UUID, preserves the `original_filename`, uses a server-generated storage filename distinct from the original, and has `status = 'ready'`.
6. THE feature test suite SHALL verify that a client cannot select a different event id, cannot choose the storage path, and cannot manipulate the status.
7. THE feature test suite SHALL verify that requests exceeding the Upload_Rate_Limit are throttled.
