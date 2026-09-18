# Requirements Document

## Introduction

This document specifies the requirements for **Phase 7 of MomentGather: local image processing**. When a guest uploads a photo to an event, the system processes the image synchronously inside the upload request. The system preserves the original upload unchanged, generates an optimized gallery image and a thumbnail, records their storage paths, and marks the photo `ready` only when processing succeeds. The public gallery is updated so the grid displays the thumbnail, the full-screen viewer displays the optimized image, and the download endpoint continues to serve the original.

Phases 1 through 6 (authentication, dashboard, Event CRUD/status, public event page, QR code, guest upload, and public gallery) are complete. This phase builds on top of that foundation and MUST NOT recreate the project or alter unrelated functionality.

### Documented Technical Decisions

The following decisions have been made by the product owner and drive these requirements:

- **Image library**: `intervention/image` v3 (compatible with Laravel 13 / PHP 8.3), using the **GD driver**. Installed via Composer. The system instantiates `Intervention\Image\ImageManager` with the GD driver directly (no `intervention/image-laravel` wrapper required). Exactly one image library is installed.
- **PHP extension requirement**: This phase requires the PHP `gd` and `exif` extensions. Both extensions (plus WebP support in GD) have been enabled and verified in the local `php.ini` (gd=yes, exif=yes, webp=yes). This is a documented **local development environment requirement**. Production server configuration MUST NOT be changed silently as part of this phase; the extension requirement is documented here.
- **Output format**: The optimized image and the thumbnail are both encoded as **WebP**. The original upload is preserved **unchanged in its original format** (JPG/JPEG/PNG/WEBP).
- **EXIF orientation**: The Intervention Image v3 GD driver auto-orients images on `read()` based on EXIF orientation. GD discards EXIF metadata on encode, but pixels are correctly rotated, which is the desired outcome. The optimized image and thumbnail are correctly oriented; the preserved original is untouched.
- **Storage layout**: The existing event-scoped `public` disk convention is kept. Originals remain at `events/{event_uuid}/originals/{photo_uuid}.{ext}` (from Phase 5, unchanged). Processed siblings are added at `events/{event_uuid}/optimized/{photo_uuid}.webp` and `events/{event_uuid}/thumbnails/{photo_uuid}.webp`. No parallel top-level scheme is introduced.
- **Synchronous processing**: Processing happens synchronously within the upload request. No queues, jobs, Redis, Horizon, S3, CDN, AI, or video are used. Moving processing to a background queue is deferred to Phase 8.
- **Database changes**: A new migration adds nullable `optimized_path` and `thumbnail_path` columns to the `photos` table. Columns are nullable so existing rows remain valid. The existing photos migration MUST NOT be edited.
- **Legacy photo handling**: Existing Phase 5/6 photos have null `optimized_path`/`thumbnail_path`. URL accessors fall back to the original URL everywhere for these legacy photos.
- **Failure handling**: If processing fails, the photo is not marked `ready`; it is marked `failed`, partially generated processed files are cleaned up, and a friendly error (no exception details) is surfaced to the guest. Failed photos are excluded from the gallery.

### Scope Summary

In scope: one Composer package, one migration (two nullable columns), Photo model changes (fillable + three URL accessors), an `App\Services\PhotoProcessor` service, upload controller integration, gallery controller payload change, `GalleryPhoto` TypeScript type plus `PhotoCard`/`PhotoViewer` tweaks, an optional `photos:process` artisan backfill command, and feature tests.

Out of scope: queues, DB queue workers, Redis, Horizon, background workers, S3, DigitalOcean Spaces, CDN, AI, duplicate detection, face recognition, video, payments, subscriptions, and deployment.

## Glossary

- **System**: The MomentGather Laravel 13 application.
- **PhotoProcessor**: The `App\Services\PhotoProcessor` service responsible for generating the optimized image and thumbnail from a stored original.
- **Upload_Controller**: `App\Http\Controllers\PublicPhotoUploadController`, which handles guest photo uploads at the `store` action.
- **Gallery_Controller**: `App\Http\Controllers\PublicGalleryController`, which builds the public gallery payload at the `show` action.
- **Download_Controller**: `App\Http\Controllers\PublicPhotoDownloadController`, which streams the original file as an attachment.
- **Photo_Model**: The `App\Models\Photo` Eloquent model.
- **Backfill_Command**: The optional `php artisan photos:process` artisan command that generates processed versions for existing ready photos lacking them.
- **Original**: The unmodified uploaded file stored at `events/{event_uuid}/originals/{photo_uuid}.{ext}`.
- **Optimized_Image**: A WebP image with a maximum dimension of 2048 pixels, stored at `events/{event_uuid}/optimized/{photo_uuid}.webp`.
- **Thumbnail**: A WebP image with a maximum dimension of 500 pixels, stored at `events/{event_uuid}/thumbnails/{photo_uuid}.webp`.
- **Maximum_Dimension**: The larger of an image's width and height, in pixels.
- **Scale_Down_Only**: A resize operation that reduces an image so its Maximum_Dimension equals the target, preserving aspect ratio, and never enlarges an image whose Maximum_Dimension is already at or below the target.
- **Public_Disk**: The Laravel `public` filesystem disk, served via the existing storage symlink.
- **Ready_Status**: The `Photo` status value `'ready'` (`Photo::STATUS_READY`), indicating a fully processed photo eligible for the gallery.
- **Failed_Status**: The `Photo` status value `'failed'` (`Photo::STATUS_FAILED`), indicating processing did not complete successfully.
- **GalleryPhoto**: The TypeScript type describing a photo entry in the gallery payload consumed by the React frontend.
- **Supported_Format**: One of the accepted upload formats: JPG, JPEG, PNG, or WEBP.

## Requirements

### Requirement 1: Install Image Processing Library

**User Story:** As a developer, I want a single image processing library installed, so that the System can decode, resize, and encode images locally.

#### Acceptance Criteria

1. THE System SHALL use `intervention/image` version 3 as the sole image processing library.
2. THE System SHALL instantiate the image manager using the GD driver.
3. THE System SHALL require the PHP `gd` and `exif` extensions to be enabled for image processing to function.
4. WHERE the deployment target is the local development environment, THE System SHALL document the `gd` and `exif` extension requirement in the requirements record rather than modifying production server configuration.

### Requirement 2: Synchronous Processing Flow

**User Story:** As a guest, I want my uploaded photo processed immediately during upload, so that a gallery-ready version is available without waiting for a background job.

#### Acceptance Criteria

1. WHEN a guest submits a photo upload, THE Upload_Controller SHALL validate the upload, store the Original, invoke the PhotoProcessor, save the resulting paths, and set the photo status before returning a response.
2. WHEN processing of a photo completes successfully, THE Upload_Controller SHALL set that photo status to Ready_Status.
3. THE Upload_Controller SHALL perform image processing synchronously within the upload request without using a queue or background worker.
4. THE Upload_Controller SHALL process one uploaded image at a time within the existing upload loop.

### Requirement 3: Preserve Original

**User Story:** As an event host, I want the original uploaded file preserved unchanged, so that guests can always download the full-quality photo.

#### Acceptance Criteria

1. WHEN a guest uploads a photo, THE Upload_Controller SHALL store the Original at `events/{event_uuid}/originals/{photo_uuid}.{ext}` on the Public_Disk.
2. THE System SHALL retain the Original in its uploaded format without converting it during this phase.
3. WHILE processing a successful upload, THE System SHALL leave the stored Original byte-for-byte unchanged.

### Requirement 4: Generate Optimized Image

**User Story:** As a guest viewing the gallery, I want an optimized version of each photo, so that the full-screen viewer loads a smaller file than the original.

#### Acceptance Criteria

1. WHEN processing a photo, THE PhotoProcessor SHALL generate an Optimized_Image whose Maximum_Dimension is at most 2048 pixels.
2. WHEN the Original Maximum_Dimension is at most 2048 pixels, THE PhotoProcessor SHALL produce an Optimized_Image at the original dimensions without upscaling.
3. WHEN generating the Optimized_Image, THE PhotoProcessor SHALL preserve the aspect ratio of the Original.
4. THE PhotoProcessor SHALL encode the Optimized_Image as WebP.
5. THE PhotoProcessor SHALL store the Optimized_Image at `events/{event_uuid}/optimized/{photo_uuid}.webp` on the Public_Disk.

### Requirement 5: Generate Thumbnail

**User Story:** As a guest browsing the gallery grid, I want a small thumbnail of each photo, so that the grid loads quickly.

#### Acceptance Criteria

1. WHEN processing a photo, THE PhotoProcessor SHALL generate a Thumbnail whose Maximum_Dimension is at most 500 pixels.
2. WHEN the Original Maximum_Dimension is at most 500 pixels, THE PhotoProcessor SHALL produce a Thumbnail at the original dimensions without upscaling.
3. WHEN generating the Thumbnail, THE PhotoProcessor SHALL preserve the aspect ratio of the Original using a scale-down fit without cropping.
4. THE PhotoProcessor SHALL encode the Thumbnail as WebP.
5. THE PhotoProcessor SHALL store the Thumbnail at `events/{event_uuid}/thumbnails/{photo_uuid}.webp` on the Public_Disk.

### Requirement 6: EXIF Orientation

**User Story:** As a guest who uploads photos from a phone, I want portrait photos to display upright, so that the gallery shows images in the correct orientation.

#### Acceptance Criteria

1. WHEN reading an Original that carries EXIF orientation metadata, THE PhotoProcessor SHALL rotate the decoded pixels to their upright orientation before resizing.
2. THE PhotoProcessor SHALL produce the Optimized_Image and Thumbnail in upright orientation.
3. THE System SHALL leave the Original file untouched regardless of its EXIF orientation.

### Requirement 7: Supported Formats

**User Story:** As an event host, I want uploads restricted to common image formats, so that only processable images enter the system.

#### Acceptance Criteria

1. THE Upload_Controller SHALL accept uploads only in the Supported_Format set: JPG, JPEG, PNG, and WEBP.
2. THE System SHALL retain the Phase 5 upload validation rules without expanding the accepted format set.

### Requirement 8: Output Format Decision

**User Story:** As a developer, I want processed images in a consistent modern format, so that gallery images are small while the original remains fully usable.

#### Acceptance Criteria

1. THE PhotoProcessor SHALL encode both the Optimized_Image and the Thumbnail as WebP.
2. THE System SHALL keep the Original in its uploaded format.
3. THE System SHALL document the WebP output decision in the requirements record.

### Requirement 9: Database Schema Changes

**User Story:** As a developer, I want the processed image paths recorded in the database, so that the application can locate the optimized image and thumbnail.

#### Acceptance Criteria

1. THE System SHALL add a nullable `optimized_path` column and a nullable `thumbnail_path` column to the `photos` table via a new migration.
2. THE System SHALL introduce the new columns through a new migration file without editing the existing `photos` migration.
3. WHERE a photo row predates this phase, THE System SHALL treat null `optimized_path` and null `thumbnail_path` as valid.

### Requirement 10: Photo Model Updates

**User Story:** As a developer, I want the Photo model to expose URLs for each image variant, so that controllers and views can render the correct file.

#### Acceptance Criteria

1. THE Photo_Model SHALL include `optimized_path` and `thumbnail_path` in its fillable attributes.
2. THE Photo_Model SHALL expose an original URL accessor that returns the Public_Disk URL of `original_path`.
3. THE Photo_Model SHALL expose an optimized URL accessor that returns the Public_Disk URL of `optimized_path`.
4. THE Photo_Model SHALL expose a thumbnail URL accessor that returns the Public_Disk URL of `thumbnail_path`.
5. IF `optimized_path` is null, THEN THE Photo_Model SHALL return the original URL from the optimized URL accessor.
6. IF `thumbnail_path` is null, THEN THE Photo_Model SHALL return the original URL from the thumbnail URL accessor.

### Requirement 11: Gallery Integration

**User Story:** As a guest browsing an event gallery, I want the grid to load thumbnails and the viewer to show optimized images, so that the gallery is fast and downloads stay full quality.

#### Acceptance Criteria

1. WHEN building the gallery payload, THE Gallery_Controller SHALL include the thumbnail URL and the optimized URL for each photo.
2. THE gallery grid SHALL render each photo using its thumbnail URL.
3. THE gallery viewer SHALL render each photo using its optimized URL.
4. THE Download_Controller SHALL continue to serve the Original for photo downloads.
5. WHERE a photo lacks processed paths, THE Gallery_Controller SHALL supply the original URL for both the thumbnail URL and the optimized URL.
6. THE Gallery_Controller SHALL include only photos with Ready_Status in the gallery payload.

### Requirement 12: Storage URL Resolution

**User Story:** As a developer, I want image URLs resolved through the storage abstraction, so that the existing public symlink is reused consistently.

#### Acceptance Criteria

1. THE System SHALL resolve all image URLs using the Public_Disk URL helper.
2. THE System SHALL NOT construct image URLs by manually concatenating filesystem paths.

### Requirement 13: Server-Generated File Naming

**User Story:** As a developer, I want processed files named by the photo UUID, so that storage names are predictable and never derived from client input.

#### Acceptance Criteria

1. THE PhotoProcessor SHALL name the Optimized_Image `{photo_uuid}.webp` within the `optimized` subfolder.
2. THE PhotoProcessor SHALL name the Thumbnail `{photo_uuid}.webp` within the `thumbnails` subfolder.
3. THE System SHALL derive processed file names from server-controlled values and SHALL NOT use the client-provided filename as a storage name.

### Requirement 14: Processing Failure Handling

**User Story:** As a guest, I want a friendly response when a photo cannot be processed, so that a failure does not break my upload or expose technical details.

#### Acceptance Criteria

1. IF processing of a photo throws an error, THEN THE Upload_Controller SHALL set that photo status to Failed_Status.
2. IF processing of a photo throws an error, THEN THE Upload_Controller SHALL delete any partially generated Optimized_Image and Thumbnail files for that photo.
3. IF processing of a photo throws an error, THEN THE Upload_Controller SHALL return a friendly error message that omits exception details.
4. THE Gallery_Controller SHALL exclude photos with Failed_Status from the gallery payload.

### Requirement 15: File Cleanup Rules

**User Story:** As an event host, I want storage to stay consistent after uploads, so that only usable files remain and existing photos are never removed.

#### Acceptance Criteria

1. WHEN processing of a photo succeeds, THE System SHALL retain the Original, the Optimized_Image, and the Thumbnail.
2. IF processing of a photo fails, THEN THE System SHALL remove the partially generated processed files for that photo.
3. THE System SHALL NOT automatically delete existing Original files that predate this phase.

### Requirement 16: Backfill Existing Photos

**User Story:** As a developer, I want to generate processed versions for existing photos, so that photos uploaded before this phase gain thumbnails and optimized images.

#### Acceptance Criteria

1. THE Backfill_Command SHALL generate an Optimized_Image and Thumbnail for each existing photo with Ready_Status that lacks processed paths.
2. WHEN a photo already has both processed paths, THE Backfill_Command SHALL skip that photo.
3. WHEN a photo's Original file is missing, THE Backfill_Command SHALL skip that photo.
4. THE Backfill_Command SHALL produce the same result when run more than once against the same data set.
5. THE Backfill_Command SHALL NOT delete existing photo records.

### Requirement 17: Upload Limits

**User Story:** As an event host, I want upload limits preserved, so that guests cannot overwhelm the event with oversized or excessive uploads.

#### Acceptance Criteria

1. THE Upload_Controller SHALL accept at most 20 files per upload request.
2. THE Upload_Controller SHALL accept each file up to a maximum size of 20480 kilobytes.
3. THE Upload_Controller SHALL retain the Phase 5 upload limits without change.

### Requirement 18: Upload Security

**User Story:** As an event host, I want uploads validated on the server, so that malicious or malformed files cannot pass as images.

#### Acceptance Criteria

1. THE Upload_Controller SHALL validate every upload on the server side.
2. THE System SHALL NOT rely solely on the client-provided filename, MIME type, or extension to determine that an upload is a valid image.
3. IF the image library cannot decode an uploaded file as an image, THEN THE Upload_Controller SHALL treat the upload as invalid.
4. THE System SHALL associate each processed file with the correct Photo and Event using server-generated names and paths.

### Requirement 19: Memory Considerations

**User Story:** As a developer, I want image processing to stay within reasonable memory bounds, so that large uploads do not exhaust available memory.

#### Acceptance Criteria

1. THE Upload_Controller SHALL process a single image at a time during an upload request.
2. THE System SHALL document a PHP `memory_limit` consideration for large images rather than modifying server configuration silently.

### Requirement 20: Frontend Integration

**User Story:** As a guest, I want the gallery frontend to use the appropriate image variant in each context, so that browsing is fast and downloads stay full quality.

#### Acceptance Criteria

1. THE GalleryPhoto type SHALL include a thumbnail URL field and an optimized URL field.
2. THE gallery grid photo card SHALL render its image using the thumbnail URL field.
3. THE gallery viewer SHALL render its image using the optimized URL field.
4. WHERE a photo lacks processed paths, THE frontend SHALL render the original URL supplied for the thumbnail URL and optimized URL fields.
5. THE upload interface SHALL remain unchanged apart from processed image handling.

### Requirement 21: Regression Safety

**User Story:** As a maintainer, I want existing behavior preserved, so that Phase 7 does not break earlier phases.

#### Acceptance Criteria

1. THE System SHALL preserve the functionality delivered in Phases 1 through 6.
2. THE System SHALL keep the existing automated test suite passing.
3. THE System SHALL NOT recreate the project or alter unrelated functionality.
