<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_admin_can_list_all_photos(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create 2 different users with events
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = Event::factory()->for($userA, 'user')->create();
        $eventB = Event::factory()->for($userB, 'user')->create();

        // Create photos for each event
        $photoA = Photo::factory()->for($eventA, 'event')->create(['original_filename' => 'photo-a.jpg']);
        $photoB = Photo::factory()->for($eventB, 'event')->create(['original_filename' => 'photo-b.jpg']);

        // Create admin user
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin requests photos list
        $response = $this->actingAs($admin)->get('/admin/photos');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Photos/Index')
            ->has('photos.data', 2)
        );
    }

    #[Test]
    public function test_admin_can_view_photo_detail_without_paths(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create photo with paths
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'user')->create();
        $photo = Photo::factory()->for($event, 'event')->create([
            'original_path' => 'photos/original.jpg',
            'optimized_path' => 'photos/optimized.jpg',
            'thumbnail_path' => 'photos/thumbnail.jpg',
        ]);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin views photo detail
        $response = $this->actingAs($admin)->get("/admin/photos/{$photo->uuid}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Photos/Show')
            ->where('photo.uuid', $photo->uuid)
            ->missing('photo.original_path')
            ->missing('photo.optimized_path')
            ->missing('photo.thumbnail_path')
        );
    }

    #[Test]
    public function test_admin_can_delete_photo_and_cleanup_files(): void
    {
        // Fake storage
        Storage::fake('public');

        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create photo with fake files
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'user')->create();
        
        $originalPath = 'photos/original.jpg';
        $optimizedPath = 'photos/optimized.jpg';
        $thumbnailPath = 'photos/thumbnail.jpg';
        
        Storage::disk('public')->put($originalPath, 'fake-original-content');
        Storage::disk('public')->put($optimizedPath, 'fake-optimized-content');
        Storage::disk('public')->put($thumbnailPath, 'fake-thumbnail-content');
        
        $photo = Photo::factory()->for($event, 'event')->create([
            'original_path' => $originalPath,
            'optimized_path' => $optimizedPath,
            'thumbnail_path' => $thumbnailPath,
        ]);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin deletes photo
        $response = $this->actingAs($admin)->delete("/admin/photos/{$photo->uuid}");

        $response->assertRedirect();

        // Verify photo is soft-deleted
        $this->assertSoftDeleted('photos', ['id' => $photo->id]);

        // Verify all 3 files are deleted from storage
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertMissing($optimizedPath);
        Storage::disk('public')->assertMissing($thumbnailPath);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'photo_deleted',
            'target_type' => 'Photo',
            'target_id' => $photo->id,
        ]);
    }

    #[Test]
    public function test_organizer_cannot_access_admin_photo_routes(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'organizer'], ['display_name' => 'Organizer']);

        // Create organizer
        $organizer = User::factory()->create();

        // Organizer tries to access admin photos route
        $response = $this->actingAs($organizer)->get('/admin/photos');

        $response->assertStatus(403);
    }
}
