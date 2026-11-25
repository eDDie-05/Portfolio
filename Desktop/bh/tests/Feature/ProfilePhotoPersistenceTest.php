<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_photo_remains_available_after_logout_and_login_on_the_private_disk(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.private_disk', 's3');

        $user = new User;
        $user->forceFill([
            'name' => 'HR Admin',
            'email' => 'hr@example.test',
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => 'system_admin',
            'is_active' => true,
        ])->save();

        $this->actingAs($user)->put(route('account.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'SecureStartPassword123!',
            'profile_photo' => UploadedFile::fake()->image('profile.png'),
        ])->assertRedirect();

        $photoPath = $user->fresh()->profile_photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('s3')->assertExists($photoPath);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('account.profile-photo'))->assertRedirect(route('login'));
        $this->post(route('login.attempt'), [
            'email' => 'hr@example.test',
            'password' => 'SecureStartPassword123!',
        ])->assertRedirect();

        $this->get(route('account.profile-photo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', 'inline; filename='.basename($photoPath));
    }
}
