<?php

namespace Tests\Feature;

use App\Livewire\Invitations\Accept as AcceptScreen;
use App\Livewire\Settings\Index as SettingsScreen;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\TeamInvitations;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Letting somebody else in.
 *
 * The settings screen used to say, in so many words, that invitations and role
 * changes "arrive with the full user-management module" — so a business with
 * two people had exactly one way to give the second one a login, which was to
 * hand over the first one's password.
 *
 * The parts worth being careful about are the ones where a mistake cannot be
 * undone from inside the product: demoting yourself out of the settings screen,
 * removing the owner, or an invitation link that keeps working after it has
 * been used.
 */
class TeamInvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->owner = User::factory()->create(['name' => 'Jean Owner']);
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    // ───────────────────────────────────────────────────── inviting ──

    public function test_inviting_somebody_new_creates_a_pending_membership(): void
    {
        $user = $this->invite('marie@example.com');

        $this->assertSame('marie@example.com', $user->email);
        $this->assertNull($user->password, 'They choose their own when they accept.');
        $this->assertSame('invited', $this->pivot($user)->status);
        $this->assertNotNull($this->pivot($user)->invitation_token);
    }

    public function test_the_invitation_is_emailed(): void
    {
        $user = $this->invite('marie@example.com');

        Notification::assertSentTo($user, TeamInvitationNotification::class);
    }

    public function test_an_invited_person_shows_on_the_team_before_they_accept(): void
    {
        $this->invite('marie@example.com');

        Livewire::actingAs($this->owner)
            ->test(SettingsScreen::class)
            ->assertSee('marie@example.com')
            ->assertSee('Invited');
    }

    public function test_somebody_who_already_has_an_account_is_invited_without_a_second_one(): void
    {
        $existing = User::factory()->create(['email' => 'compta@example.com']);

        $user = $this->invite('compta@example.com');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame(1, User::query()->where('email', 'compta@example.com')->count());
    }

    public function test_the_address_is_normalised(): void
    {
        $user = $this->invite('  Marie@Example.COM ');

        $this->assertSame('marie@example.com', $user->email);
    }

    public function test_somebody_already_on_the_team_cannot_be_invited_again(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::CASHIER);

        $this->expectException(RuntimeException::class);
        $this->invite($member->email);
    }

    public function test_nobody_can_be_invited_as_owner(): void
    {
        $this->expectException(RuntimeException::class);

        app(TeamInvitations::class)->invite(
            $this->company, 'x@example.com', Role::where('slug', Role::OWNER)->first(), null, $this->owner
        );
    }

    // ─────────────────────────────────────────────────── accepting ──

    public function test_accepting_sets_a_password_and_joins_the_business(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        $accepted = app(TeamInvitations::class)->accept($token, [
            'name' => 'Marie Ngo',
            'password' => 'a-good-password',
        ]);

        $this->assertSame('Marie Ngo', $accepted->name);
        $this->assertTrue(Hash::check('a-good-password', $accepted->password));
        $this->assertSame('active', $this->pivot($accepted)->status);
        $this->assertNotNull($this->pivot($accepted)->joined_at);
        $this->assertSame($this->company->id, $accepted->current_company_id);
    }

    /** Single use. The token is cleared, so a forwarded email is a dead link. */
    public function test_an_accepted_invitation_cannot_be_used_twice(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        app(TeamInvitations::class)->accept($token, ['name' => 'Marie', 'password' => 'a-good-password']);

        $this->assertNull($this->pivot($user)->invitation_token);
        $this->assertNull(app(TeamInvitations::class)->findByToken($token));

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->accept($token, ['name' => 'X', 'password' => 'another-password']);
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        DB::table('company_user')
            ->where('user_id', $user->id)
            ->update(['invitation_expires_at' => now()->subDay()]);

        $this->assertNull(app(TeamInvitations::class)->findByToken($token));
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->assertNull(app(TeamInvitations::class)->findByToken('not-a-real-token'));
    }

    /**
     * An existing account joins without touching its password. Otherwise anyone
     * who was forwarded the email could reset a stranger's credentials.
     */
    public function test_an_existing_account_keeps_its_password(): void
    {
        $existing = User::factory()->create(['email' => 'compta@example.com', 'password' => 'their-own-password']);

        $user = $this->invite('compta@example.com');
        $token = $this->pivot($user)->invitation_token;

        app(TeamInvitations::class)->accept($token, ['name' => 'Impostor', 'password' => 'taken-over']);

        $existing->refresh();
        $this->assertTrue(Hash::check('their-own-password', $existing->password));
        $this->assertNotSame('Impostor', $existing->name);
        $this->assertSame('active', $this->pivot($existing)->status);
    }

    public function test_accepting_without_a_password_is_refused_for_a_new_account(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->accept($token, ['name' => 'Marie']);
    }

    public function test_resending_replaces_the_secret(): void
    {
        $user = $this->invite('marie@example.com');
        $first = $this->pivot($user)->invitation_token;

        app(TeamInvitations::class)->resend($this->company, $user, $this->owner);
        $second = $this->pivot($user)->invitation_token;

        $this->assertNotSame($first, $second);
        $this->assertNull(app(TeamInvitations::class)->findByToken($first), 'The old link stops working.');
        $this->assertNotNull(app(TeamInvitations::class)->findByToken($second));
    }

    // ──────────────────────────────────────────────── logging in ──

    /** An account with no password must not be reachable by leaving the field empty. */
    public function test_an_invited_account_cannot_be_logged_into(): void
    {
        $this->invite('marie@example.com');

        $this->post('/login', ['email' => 'marie@example.com', 'password' => ''])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    // ───────────────────────────────────────────── roles and removal ──

    public function test_a_role_can_be_changed(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::CASHIER);

        $manager = Role::where('slug', Role::MANAGER)->first();
        app(TeamInvitations::class)->changeRole($this->company, $member, $manager, $this->owner);

        $this->assertSame($manager->id, $this->pivot($member)->role_id);
    }

    /**
     * The mistake with no way back: an administrator who demotes themselves can
     * no longer reach the screen that would undo it.
     */
    public function test_nobody_can_change_their_own_role(): void
    {
        $admin = User::factory()->create();
        $this->joinCompany($this->company, $admin, Role::ADMINISTRATOR);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->changeRole(
            $this->company, $admin, Role::where('slug', Role::CASHIER)->first(), $admin
        );
    }

    public function test_the_owners_role_cannot_be_changed(): void
    {
        $admin = User::factory()->create();
        $this->joinCompany($this->company, $admin, Role::ADMINISTRATOR);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->changeRole(
            $this->company, $this->owner, Role::where('slug', Role::CASHIER)->first(), $admin
        );
    }

    public function test_nobody_can_be_promoted_to_owner(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::CASHIER);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->changeRole(
            $this->company, $member, Role::where('slug', Role::OWNER)->first(), $this->owner
        );
    }

    public function test_removing_somebody_ends_their_access(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::CASHIER);

        app(TeamInvitations::class)->remove($this->company, $member, $this->owner);

        $this->assertSame(0, $this->company->users()->where('users.id', $member->id)->count());
        $this->assertNotNull($member->fresh(), 'The person still exists — their work has their name on it.');
    }

    public function test_removing_somebody_takes_their_extra_grants_with_them(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::CASHIER);

        DB::table('company_user_permission')->insert([
            'company_id' => $this->company->id,
            'user_id' => $member->id,
            'permission_id' => DB::table('permissions')->value('id'),
            'granted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TeamInvitations::class)->remove($this->company, $member, $this->owner);

        $this->assertSame(0, DB::table('company_user_permission')
            ->where('company_id', $this->company->id)->where('user_id', $member->id)->count());
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $admin = User::factory()->create();
        $this->joinCompany($this->company, $admin, Role::ADMINISTRATOR);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->remove($this->company, $this->owner, $admin);
    }

    public function test_nobody_can_remove_themselves(): void
    {
        $admin = User::factory()->create();
        $this->joinCompany($this->company, $admin, Role::ADMINISTRATOR);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->remove($this->company, $admin, $admin);
    }

    public function test_a_member_of_another_business_cannot_be_touched(): void
    {
        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $stranger->id,
            'currency' => 'XAF', 'plan' => 'basic', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);

        $this->expectException(RuntimeException::class);
        app(TeamInvitations::class)->remove($this->company, $stranger, $this->owner);
    }

    public function test_withdrawing_an_invitation_removes_the_pending_row(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        app(TeamInvitations::class)->cancelInvitation($this->company, $user, $this->owner);

        $this->assertSame(0, $this->company->users()->where('users.id', $user->id)->count());
        $this->assertNull(app(TeamInvitations::class)->findByToken($token));
    }

    // ───────────────────────────────────────────────────── screens ──

    public function test_the_settings_screen_sends_an_invitation(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsScreen::class)
            ->call('startInviting')
            ->assertSet('inviting', true)
            ->set('inviteEmail', 'marie@example.com')
            ->set('inviteJobTitle', 'Caissière')
            ->call('sendInvite')
            ->assertHasNoErrors()
            ->assertSet('inviting', false);

        $user = User::query()->where('email', 'marie@example.com')->firstOrFail();
        $this->assertSame('Caissière', $this->pivot($user)->job_title);
    }

    public function test_the_settings_screen_refuses_a_duplicate(): void
    {
        $member = User::factory()->create(['email' => 'already@example.com']);
        $this->joinCompany($this->company, $member, Role::CASHIER);

        Livewire::actingAs($this->owner)
            ->test(SettingsScreen::class)
            ->call('startInviting')
            ->set('inviteEmail', 'already@example.com')
            ->call('sendInvite')
            ->assertHasErrors('inviteEmail');
    }

    public function test_someone_without_the_permission_cannot_invite(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        Livewire::actingAs($cashier)
            ->test(SettingsScreen::class)
            ->call('startInviting')
            ->assertForbidden();
    }

    public function test_the_accept_screen_creates_the_account_and_signs_them_in(): void
    {
        $user = $this->invite('marie@example.com');
        $token = $this->pivot($user)->invitation_token;

        Livewire::test(AcceptScreen::class, ['token' => $token])
            ->assertSet('needsPassword', true)
            ->assertSee('Acme Sarl')
            ->set('name', 'Marie Ngo')
            ->set('password', 'a-good-password')
            ->set('passwordConfirmation', 'a-good-password')
            ->call('accept')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('active', $this->pivot($user)->status);
    }

    public function test_the_accept_screen_wants_the_two_passwords_to_match(): void
    {
        $user = $this->invite('marie@example.com');

        Livewire::test(AcceptScreen::class, ['token' => $this->pivot($user)->invitation_token])
            ->set('name', 'Marie Ngo')
            ->set('password', 'a-good-password')
            ->set('passwordConfirmation', 'a-different-one')
            ->call('accept')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    /**
     * Spent, unknown and expired all read the same, so guessing tokens tells
     * nobody whether they have found a real one.
     */
    public function test_a_dead_link_says_so_without_saying_why(): void
    {
        Livewire::test(AcceptScreen::class, ['token' => 'nonsense'])
            ->assertSet('company', null)
            ->assertSee('no longer valid');
    }

    public function test_the_accept_page_is_reachable_without_signing_in(): void
    {
        $user = $this->invite('marie@example.com');

        $this->get(route('invitations.show', $this->pivot($user)->invitation_token))
            ->assertOk()
            ->assertSee('Acme Sarl');
    }

    // ───────────────────────────────────────────────────── helpers ──

    protected function invite(string $email, string $role = Role::SALES_OFFICER): User
    {
        return app(TeamInvitations::class)->invite(
            $this->company,
            $email,
            Role::where('slug', $role)->firstOrFail(),
            null,
            $this->owner,
        );
    }

    protected function pivot(User $user): object
    {
        return DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
