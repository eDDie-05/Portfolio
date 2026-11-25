<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function profilePhoto(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_photo_path;
        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function edit(Request $request): View
    {
        return view('account.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required_with:password', 'nullable', 'current_password'],
            'password' => ['nullable', Password::min(12)->letters()->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);

        $updates = ['name' => $data['name'], 'email' => mb_strtolower($data['email'])];
        $passwordChanged = filled($data['password'] ?? null);
        if ($passwordChanged) {
            $updates['password'] = Hash::make($data['password']);
            $updates['remember_token'] = Str::random(60);
            $updates['must_change_password'] = false;
        }

        DB::transaction(function () use ($request, $user, $updates, $passwordChanged): void {
            $user->forceFill($updates)->save();
            if ($passwordChanged && config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }
            DB::table('audit_logs')->insert([
                'user_id' => $user->id,
                'action' => $passwordChanged ? 'account.credentials_updated' : 'account.profile_updated',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'details' => json_encode(['name_changed' => $user->wasChanged('name'), 'email_changed' => $user->wasChanged('email'), 'password_changed' => $passwordChanged]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $request->session()->regenerate();

        return back()->with('status', $passwordChanged ? 'Account details and password updated. Other signed-in sessions were revoked.' : 'Account details updated.');
    }

    public function updateProfilePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $oldPhoto = $user->profile_photo_path;

        try {
            $photoPath = $data['profile_photo']->store(
                'profile-photos/'.$user->id,
                config('filesystems.private_disk')
            );
        } catch (FilesystemException $exception) {
            Log::error('Profile photo upload failed.', ['user_id' => $user->id, 'exception' => $exception]);

            throw ValidationException::withMessages([
                'profile_photo' => 'The profile picture could not be saved. Please try again.',
            ]);
        }

        if (! is_string($photoPath) || $photoPath === '') {
            throw ValidationException::withMessages([
                'profile_photo' => 'The profile picture could not be saved. Please try again.',
            ]);
        }

        $user->forceFill(['profile_photo_path' => $photoPath])->save();

        if ($oldPhoto && $oldPhoto !== $photoPath) {
            try {
                Storage::disk(config('filesystems.private_disk'))->delete($oldPhoto);
            } catch (FilesystemException $exception) {
                Log::warning('Previous profile photo could not be deleted after replacement.', [
                    'user_id' => $user->id,
                    'exception' => $exception,
                ]);
            }
        }

        return back()->with('status', 'Profile picture saved.');
    }
}
