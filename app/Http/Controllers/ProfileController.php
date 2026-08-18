<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Nigeria;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->profileOrNew();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'profile' => [
                'display_name' => $profile->display_name,
                'phone' => $profile->phone,
                'whatsapp' => $profile->whatsapp,
                'state' => $profile->state,
                'lga' => $profile->lga,
                'bio' => $profile->bio,
                'avatar_url' => $profile->avatarUrl(),
            ],
            'states' => Nigeria::states(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            $user->fill($request->userAttributes());

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            $profile = $user->profile()->firstOrNew();
            $profile->fill($request->profileAttributes());

            if ($request->hasFile('avatar')) {
                $disk = Storage::disk(config('filesystems.default'));

                if (filled($profile->avatar)) {
                    $disk->delete($profile->avatar);
                }

                $profile->avatar = $request->file('avatar')->store('avatars', [
                    'disk' => config('filesystems.default'),
                ]);
            }

            $user->profile()->save($profile);
        });

        return Redirect::route('profile.edit')->with('success', __('Profile updated.'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
