<?php

namespace App\Services\Mentorship;

use App\Enums\ContactMethod;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\MentorInvitationMail;
use App\Models\MentorInvitation;
use App\Models\MentorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Getting a mentor into the platform.
 *
 * Mentors join by invitation and by no other route. That is a commercial
 * decision, not a security one — the company is putting its name behind these
 * people — but it is enforced like a security one, because a signup form that
 * exists "but is not linked anywhere" is a signup form.
 *
 * Two locks, deliberately independent:
 *
 *   · the URL is signed, so nobody can construct one;
 *   · the token is checked against this table, so a signed URL whose token has
 *     been spent or has expired still opens nothing.
 *
 * The second is the one that matters. A signature proves we made the link, not
 * that the link is still good.
 */
class InvitationService
{
    /**
     * Mint an invitation and the link to send with it.
     */
    public function create(
        User $admin,
        ?string $email = null,
        ?string $name = null,
        ?string $note = null,
        ?int $days = null,
    ): MentorInvitation {
        $invitation = new MentorInvitation;

        $invitation->forceFill([
            'token' => MentorInvitation::newToken(),
            'email' => $email === null ? null : mb_strtolower(trim($email)),
            'name' => $name,
            'note' => $note,
            'created_by' => $admin->getKey(),
            'expires_at' => now()->addDays($days ?? MentorInvitation::DEFAULT_DAYS),
        ])->save();

        $invitation = $invitation->refresh();

        /*
         * Sent here, rather than left for somebody to copy out of the panel.
         *
         * There is no public mentor signup, so this link is the entire route in
         * — an invitation that is created and never delivered is a mentor who
         * never joins. Only when an address was given: an administrator may
         * still mint an open link to send over WhatsApp, which is why `email`
         * is nullable in the first place.
         */
        if ($invitation->email !== null) {
            try {
                Mail::to($invitation->email)->send(
                    new MentorInvitationMail($invitation, $this->urlFor($invitation)),
                );
            } catch (\Throwable $exception) {
                // The invitation still exists and the link still works, so this
                // is recoverable by hand — but somebody has to know to do that.
                Log::error('A mentor invitation could not be emailed.', [
                    'invitation' => $invitation->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $invitation;
    }

    /**
     * The link an administrator copies into WhatsApp.
     *
     * Signed until the invitation expires, so the two locks cannot drift apart:
     * a link that outlived its invitation would fail the token check, and one
     * that expired first would fail the signature. They end together.
     */
    public function urlFor(MentorInvitation $invitation): string
    {
        return URL::temporarySignedRoute(
            'mentors.join',
            $invitation->expires_at ?? now()->addDays(MentorInvitation::DEFAULT_DAYS),
            ['token' => $invitation->token],
        );
    }

    /**
     * Find an invitation by token, whatever state it is in.
     *
     * Deliberately returns spent and expired invitations too, so the page can
     * say *why* rather than showing a blank 404 to somebody who was genuinely
     * invited last month.
     */
    public function find(string $token): ?MentorInvitation
    {
        return MentorInvitation::query()->where('token', $token)->first();
    }

    /**
     * Turn an accepted invitation into a mentor.
     *
     * The whole thing is one transaction with the invitation spent inside it,
     * so two people opening the same forwarded link cannot both come out the
     * other side as mentors.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $specialisationIds
     */
    public function register(MentorInvitation $invitation, array $data, array $specialisationIds): MentorProfile
    {
        if (! $invitation->isOpen()) {
            throw new RuntimeException($invitation->refusalReason() ?? __('This invitation cannot be used.'));
        }

        if (! $invitation->acceptsEmail($data['email'])) {
            throw new RuntimeException(__('This invitation was sent to a different email address.'));
        }

        if ($specialisationIds === []) {
            // The free text is what a client reads; the tags are what matching
            // runs on. A mentor with no tags can never be shortlisted, so this
            // is refused rather than allowed to become a silent dead end.
            throw new RuntimeException(__('Tick at least one thing you can help with.'));
        }

        return DB::transaction(function () use ($invitation, $data, $specialisationIds): MentorProfile {
            /*
             * Spend the invitation FIRST, and re-read it under a row lock. Two
             * requests racing the same link both pass the check above; only one
             * gets past this.
             */
            $locked = MentorInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen()) {
                throw new RuntimeException(__('This invitation has already been used.'));
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => mb_strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                // Invited by the company, so the account itself is live from
                // the start. The MENTOR PROFILE is what still needs approving,
                // and that is what gates being listed and hired.
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            $user->profile()->create([
                'display_name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'state' => $data['state'] ?? null,
                'lga' => $data['lga'] ?? null,
            ]);

            $user->assignRole(RoleName::Mentor->value);

            $profile = new MentorProfile;

            $profile->forceFill([
                'user_id' => $user->getKey(),
                'headline' => $data['headline'],
                'bio' => $data['bio'],
                'strengths' => $data['strengths'],
                'years_experience' => (int) ($data['years_experience'] ?? 0),
                'qualifications' => $data['qualifications'] ?? null,
                'affiliation' => $data['affiliation'] ?? null,
                'preferred_contact_method' => ContactMethod::from($data['preferred_contact_method']),
                'contact_value' => $data['contact_value'],
                'states_served' => array_values($data['states_served'] ?? []),
                'accepts_remote' => (bool) ($data['accepts_remote'] ?? true),
                'accepts_in_person' => (bool) ($data['accepts_in_person'] ?? false),
            ])->save();

            $profile->specialisations()->sync($specialisationIds);

            $locked->redeem($user);

            return $profile->refresh();
        });
    }
}
