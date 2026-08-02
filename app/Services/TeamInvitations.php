<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Support\UniqueId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Letting somebody into the business, changing what they may do, and letting
 * them go.
 *
 * ── An invitation is a membership, not a message ─────────────────────────
 *
 * Inviting somebody writes the `company_user` row immediately, with status
 * `invited` and a secret. That way the team list shows them straight away —
 * "did I invite Marie or did I only mean to" is a question the screen should
 * answer — and accepting is a state change rather than a creation, so there is
 * no window in which two half-invitations exist for the same person.
 *
 * A person who has never used the product gets a `users` row with no password.
 * They cannot log in with it (the login form requires one and there is nothing
 * to match), and accepting is where they choose one. The alternative — holding
 * the invitation somewhere else until they sign up — means the email address
 * is unclaimed in the meantime, so two businesses inviting the same accountant
 * race each other to create them.
 *
 * ── What cannot be done ──────────────────────────────────────────────────
 *
 * Nobody may change their own role or remove themselves: an administrator who
 * demotes themselves by accident has locked the business out of its own
 * settings, and there is no undo that does not involve support. The owner is
 * likewise untouchable through this service — they are the account. Changing
 * who owns a business is a different act with different consequences (billing,
 * legal responsibility for what the books say) and does not belong behind a
 * dropdown in a team list.
 */
class TeamInvitations
{
    /** How long an invitation stays good for. */
    public const VALID_FOR_DAYS = 14;

    /**
     * Invite somebody, or re-invite somebody whose invitation lapsed.
     *
     * @throws RuntimeException when the address already belongs to a member
     */
    public function invite(
        Company $company,
        string $email,
        Role $role,
        ?string $jobTitle,
        User $actor,
    ): User {
        $email = strtolower(trim($email));

        $this->guardRole($role);

        return DB::transaction(function () use ($company, $email, $role, $jobTitle, $actor) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    // A name they have not given yet. Shown nowhere except the
                    // pending row on the team list, and replaced the moment
                    // they accept.
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => null,
                ]);
            }

            $existing = $company->users()->where('users.id', $user->id)->first();

            if ($existing !== null && $existing->pivot->status === 'active') {
                throw new RuntimeException('That person is already on the team.');
            }

            $attributes = [
                'role_id' => $role->id,
                'job_title' => $jobTitle ?: null,
                'status' => 'invited',
                'invited_at' => now(),
                'invitation_token' => $this->newToken(),
                'invitation_expires_at' => now()->addDays(self::VALID_FOR_DAYS),
                'invited_by' => $actor->id,
                'joined_at' => null,
            ];

            $existing !== null
                ? $company->users()->updateExistingPivot($user->id, $attributes)
                : $company->users()->attach($user->id, $attributes);

            $token = $attributes['invitation_token'];

            $user->notify(new TeamInvitationNotification($company, $actor, $role, $token, $user->password === null));

            return $user->refresh();
        });
    }

    /** Send the invitation again with a fresh secret and a fresh clock. */
    public function resend(Company $company, User $member, User $actor): void
    {
        $membership = $this->membership($company, $member);

        if ($membership->pivot->status !== 'invited') {
            throw new RuntimeException('That person has already accepted.');
        }

        $token = $this->newToken();

        $company->users()->updateExistingPivot($member->id, [
            'invitation_token' => $token,
            'invitation_expires_at' => now()->addDays(self::VALID_FOR_DAYS),
            'invited_at' => now(),
            'invited_by' => $actor->id,
        ]);

        $role = Role::query()->findOrFail($membership->pivot->role_id);

        $member->notify(new TeamInvitationNotification(
            $company, $actor, $role, $token, $member->password === null
        ));
    }

    /**
     * The membership a token belongs to, or null when the link is spent,
     * unknown or out of date.
     *
     * @return array{company: Company, user: User}|null
     */
    public function findByToken(string $token): ?array
    {
        $row = DB::table('company_user')
            ->where('invitation_token', $token)
            ->where('status', 'invited')
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->invitation_expires_at !== null && Carbon::parse($row->invitation_expires_at)->isPast()) {
            return null;
        }

        $company = Company::query()->find($row->company_id);
        $user = User::query()->find($row->user_id);

        if ($company === null || $user === null) {
            return null;
        }

        return ['company' => $company, 'user' => $user];
    }

    /**
     * Accept an invitation.
     *
     * The name and password are only applied to somebody who does not have
     * them. An existing user being invited to a second business has an account
     * already, and an invitation link is not the place to change its password —
     * whoever forwarded the email would be able to take the account over.
     *
     * @param  array{name?: string, password?: string}  $credentials
     */
    public function accept(string $token, array $credentials = []): User
    {
        $found = $this->findByToken($token);

        if ($found === null) {
            throw new RuntimeException('This invitation is no longer valid. Ask for a new one.');
        }

        ['company' => $company, 'user' => $user] = $found;

        return DB::transaction(function () use ($company, $user, $credentials) {
            if ($user->password === null) {
                if (($credentials['password'] ?? '') === '') {
                    throw new RuntimeException('Choose a password to finish setting up your account.');
                }

                $user->forceFill([
                    'name' => trim($credentials['name'] ?? '') ?: $user->name,
                    'password' => $credentials['password'],
                    'email_verified_at' => now(),
                ])->save();
            }

            $company->users()->updateExistingPivot($user->id, [
                'status' => 'active',
                'joined_at' => now(),
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ]);

            // Somebody with no company yet lands in this one; somebody who
            // already works elsewhere keeps whatever they were last in, and
            // switches when they choose to.
            if ($user->current_company_id === null) {
                $user->forceFill(['current_company_id' => $company->id])->save();
            }

            return $user->refresh();
        });
    }

    /**
     * Change what a member may do.
     *
     * @throws RuntimeException when it would lock somebody out or touch the owner
     */
    public function changeRole(Company $company, User $member, Role $role, User $actor): void
    {
        $this->guardRole($role);
        $this->guardNotOwner($company, $member, 'The owner’s role cannot be changed here.');
        $this->guardNotSelf($member, $actor, 'You cannot change your own role.');

        $this->membership($company, $member);

        $company->users()->updateExistingPivot($member->id, ['role_id' => $role->id]);

        // The member's resolved role is memoised for the request. Anything
        // still holding this instance must not go on answering from before.
        $member->forgetRoleCache();
    }

    /**
     * Take somebody off the team.
     *
     * Detached rather than marked removed: a membership row is a claim to see
     * this business's data, and a status column is one forgotten `wherePivot`
     * away from still honouring it. Their user account and everything they
     * created stays — an invoice keeps saying who issued it.
     */
    public function remove(Company $company, User $member, User $actor): void
    {
        $this->guardNotOwner($company, $member, 'The owner cannot be removed from their own business.');
        $this->guardNotSelf($member, $actor, 'You cannot remove yourself.');

        $this->membership($company, $member);

        DB::transaction(function () use ($company, $member) {
            $company->users()->detach($member->id);

            // Their per-user grants and revocations went with the membership.
            DB::table('company_user_permission')
                ->where('company_id', $company->id)
                ->where('user_id', $member->id)
                ->delete();

            $member->forgetRoleCache();

            if ($member->current_company_id === $company->id) {
                $member->forceFill([
                    'current_company_id' => $member->companies()->wherePivot('status', 'active')->value('companies.id'),
                ])->save();
            }
        });
    }

    /** Withdraw an invitation nobody has accepted. */
    public function cancelInvitation(Company $company, User $member, User $actor): void
    {
        $membership = $this->membership($company, $member);

        if ($membership->pivot->status !== 'invited') {
            throw new RuntimeException('That person has already accepted. Remove them instead.');
        }

        $this->remove($company, $member, $actor);
    }

    protected function membership(Company $company, User $member): User
    {
        return $company->users()->where('users.id', $member->id)->first()
            ?? throw new RuntimeException('That person is not on this team.');
    }

    /**
     * Owner is not offered. It is the account itself, and handing it over is a
     * decision with billing and legal consequences that does not belong behind
     * a dropdown in a list.
     */
    protected function guardRole(Role $role): void
    {
        if ($role->slug === Role::OWNER) {
            throw new RuntimeException('Ownership is transferred separately, not assigned from the team list.');
        }
    }

    protected function guardNotOwner(Company $company, User $member, string $message): void
    {
        if ($company->owner_id === $member->id) {
            throw new RuntimeException($message);
        }
    }

    protected function guardNotSelf(User $member, User $actor, string $message): void
    {
        if ($member->id === $actor->id) {
            throw new RuntimeException($message);
        }
    }

    /** Retried on collision, like every other secret in this system. */
    protected function newToken(): string
    {
        return UniqueId::make(
            fn () => Str::random(48),
            fn (string $token) => DB::table('company_user')->where('invitation_token', $token)->exists(),
        );
    }
}
