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

        $this->actingAs($user)->put(route('account.profile-photo.update'), [
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

    public function test_profile_photo_replacement_preserves_the_saved_photo_when_a_new_upload_is_invalid(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.private_disk', 's3');

        $user = new User;
        $user->forceFill([
            'name' => 'HR Admin',
            'email' => 'hr.replace@example.test',
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => 'system_admin',
            'is_active' => true,
        ])->save();
        $previousPhotoPath = UploadedFile::fake()->image('previous.png')->store('profile-photos/'.$user->id, 's3');
        $user->forceFill(['profile_photo_path' => $previousPhotoPath])->save();

        $this->actingAs($user)->put(route('account.profile-photo.update'), [
            'profile_photo' => UploadedFile::fake()->image('replacement.png'),
        ])->assertRedirect();

        $replacementPhotoPath = $user->fresh()->profile_photo_path;
        $this->assertNotSame($previousPhotoPath, $replacementPhotoPath);
        Storage::disk('s3')->assertExists($replacementPhotoPath);
        Storage::disk('s3')->assertMissing($previousPhotoPath);
        $this->get(route('account.profile-photo'))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->put(route('account.profile-photo.update'), [
            'profile_photo' => UploadedFile::fake()->create('not-an-image.pdf', 1024, 'application/pdf'),
        ])->assertSessionHasErrors('profile_photo');

        $this->assertSame($replacementPhotoPath, $user->fresh()->profile_photo_path);
        Storage::disk('s3')->assertExists($replacementPhotoPath);
    }

    public function test_account_details_do_not_require_a_password_when_password_is_not_changed(): void
    {
        $user = new User;
        $user->forceFill([
            'name' => 'HR Admin',
            'email' => 'hr.details@example.test',
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => 'system_admin',
            'is_active' => true,
        ])->save();

        $this->actingAs($user)->put(route('account.update'), [
            'name' => 'Updated HR Admin',
            'email' => 'updated.hr@example.test',
        ])->assertRedirect();

        $this->assertSame('Updated HR Admin', $user->fresh()->name);
        $this->assertSame('updated.hr@example.test', $user->fresh()->email);
    }

    public function test_password_change_still_requires_the_current_password(): void
    {
        $user = new User;
        $user->forceFill([
            'name' => 'HR Admin',
            'email' => 'hr.password@example.test',
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => 'system_admin',
            'is_active' => true,
        ])->save();

        $this->actingAs($user)->from(route('account.edit'))->put(route('account.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'ChangedPassword123!',
            'password_confirmation' => 'ChangedPassword123!',
        ])->assertRedirect(route('account.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('SecureStartPassword123!', $user->fresh()->password));
    }
}
