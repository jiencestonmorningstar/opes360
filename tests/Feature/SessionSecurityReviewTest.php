<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Invitations\Accept as AcceptScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Services\Stock\DeliveryReceiver;
use App\Services\TeamInvitations;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * An adversarial pass over what this batch of work added.
 *
 * Four things here are the kind that get a product breached rather than merely
 * broken, and each was introduced or changed recently enough not to have been
 * looked at with bad intent yet:
 *
 *   · a route reachable without signing in, guarded only by a secret in a URL
 *   · `users.password` becoming nullable
 *   · permission decisions memoised for the request instead of re-read
 *   · new services taking an id straight from a form and looking it up
 *
 * The tests are written from the attacker's side: not "does the feature work"
 * but "what does somebody get if they try the obvious thing".
 */
class SessionSecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Company $rival;

    protected User $rivalOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany('acme', $this->owner);

        $this->rivalOwner = User::factory()->create();
        $this->rival = $this->makeCompany('rival', $this->rivalOwner);

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);
    }

    // ────────────────────────────────── removing somebody actually removes ──

    /**
     * The one that matters most about `remove()`: a member who is signed in
     * when they are taken off the team must stop seeing the business on their
     * very next request, not when their session happens to expire.
     */
    public function test_a_removed_member_loses_access_on_their_next_request(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::SALES_OFFICER);
        $member->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($member)->get('/sales')->assertOk();

        app(TeamInvitations::class)->remove($this->company, $member, $this->owner);

        // Same session, next request.
        $this->actingAs($member->fresh())->get('/sales')->assertForbidden();
    }

    public function test_a_suspended_member_loses_access_on_their_next_request(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::SALES_OFFICER);
        $member->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($member)->get('/sales')->assertOk();

        $this->company->users()->updateExistingPivot($member->id, ['status' => 'suspended']);

        $this->actingAs($member->fresh())->get('/sales')->assertForbidden();
    }

    /**
     * The memo is keyed by company, so a user who belongs to two businesses
     * cannot carry an answer from one into the other.
     */
    public function test_a_permission_memo_does_not_leak_between_companies(): void
    {
        $accountant = User::factory()->create();
        $this->joinCompany($this->company, $accountant, Role::ACCOUNTANT);
        $this->joinCompany($this->rival, $accountant, Role::READ_ONLY);

        $this->assertTrue($accountant->hasPermissionIn($this->company, 'accounting.export'));
        $this->assertFalse(
            $accountant->hasPermissionIn($this->rival, 'accounting.export'),
            'The answer for one business must not be reused for another.'
        );
    }

    // ─────────────────────────────────────── an account with no password ──

    public function test_an_invited_account_cannot_be_logged_into_with_an_empty_password(): void
    {
        $invited = $this->invite('marie@example.com');

        foreach (['', ' ', '0', 'null'] as $attempt) {
            $this->post('/login', ['email' => $invited->email, 'password' => $attempt])
                ->assertSessionHasErrors();
            $this->assertGuest();
        }
    }

    /**
     * The 2FA challenge sits behind a passed password check, so an account with
     * no password cannot reach it — but the session key is the only thing
     * guarding that screen, so it is worth proving rather than assuming.
     */
    public function test_an_invited_account_cannot_reach_the_two_factor_screen(): void
    {
        $this->invite('marie@example.com');

        $this->get(route('two-factor.challenge'))->assertForbidden();
    }

    /**
     * Somebody who resets their password instead of accepting has a working
     * login and no membership. They must land in an empty account rather than
     * inside a business that never let them in.
     */
    public function test_resetting_a_password_does_not_substitute_for_accepting(): void
    {
        $invited = $this->invite('marie@example.com');

        // Simulate the reset having happened: they now hold a password.
        $invited->forceFill(['password' => 'a-good-password'])->save();

        $this->assertFalse($invited->fresh()->belongsToCompany($this->company));
        $this->actingAs($invited->fresh())->get('/sales')->assertForbidden();
    }

    // ──────────────────────────────────────── the token in the URL ──

    public function test_an_invitation_token_is_long_and_unguessable(): void
    {
        $invited = $this->invite('marie@example.com');
        $token = $this->tokenFor($invited);

        $this->assertGreaterThanOrEqual(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);
    }

    /** Two invitations must never be handed the same secret. */
    public function test_two_invitations_do_not_share_a_token(): void
    {
        $first = $this->tokenFor($this->invite('one@example.com'));
        $second = $this->tokenFor($this->invite('two@example.com'));

        $this->assertNotSame($first, $second);
    }

    /**
     * A wrong token must say nothing about whether it was wrong, expired or
     * already used — otherwise guessing tells an attacker when they are warm.
     */
    public function test_a_wrong_token_reveals_nothing_about_why(): void
    {
        $invited = $this->invite('marie@example.com');
        $good = $this->tokenFor($invited);

        app(TeamInvitations::class)->accept($good, ['name' => 'Marie', 'password' => 'a-good-password']);

        $spent = $this->get(route('invitations.show', $good))->getContent();
        $unknown = $this->get(route('invitations.show', Str::random(48)))->getContent();

        foreach ([$spent, $unknown] as $body) {
            $this->assertStringContainsString('no longer valid', $body);
            $this->assertStringNotContainsString('marie@example.com', $body);
            $this->assertStringNotContainsString('Acme', $body);
        }
    }

    /** The accept page must not name the business to somebody holding a dud link. */
    public function test_a_dud_link_does_not_name_the_business(): void
    {
        Livewire::test(AcceptScreen::class, ['token' => Str::random(48)])
            ->assertSet('company', null)
            ->assertDontSee('Acme');
    }

    /**
     * An invitation is a way into the *inviter's* business, never a way into
     * the invitee's account. Accepting with an existing account must not touch
     * that account's credentials — whoever was forwarded the email would
     * otherwise be able to take it over.
     */
    public function test_a_forwarded_invitation_cannot_take_over_an_existing_account(): void
    {
        $victim = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => 'the-victims-own-password',
        ]);

        $invited = $this->invite('victim@example.com');
        $token = $this->tokenFor($invited);

        app(TeamInvitations::class)->accept($token, [
            'name' => 'Attacker',
            'password' => 'attacker-chosen',
        ]);

        $victim->refresh();

        $this->assertTrue(password_verify('the-victims-own-password', $victim->password));
        $this->assertFalse(password_verify('attacker-chosen', $victim->password));
        $this->assertNotSame('Attacker', $victim->name);
    }

    /** And accepting must not sign anybody in as an account they did not prove they hold. */
    public function test_accepting_for_an_existing_account_does_not_sign_you_in(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'theirs']);

        $invited = $this->invite('victim@example.com');

        Livewire::test(AcceptScreen::class, ['token' => $this->tokenFor($invited)])
            ->assertSet('needsPassword', false)
            ->call('accept');

        // The link proves you read an inbox, not that you own the account.
        $this->assertGuest();
    }

    public function test_the_invitation_route_is_throttled(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'invitations.show');

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')),
            'A public route guarded only by a secret must be rate limited.'
        );
    }

    // ────────────────────────────── ids taken straight from a form ──

    public function test_a_delivery_cannot_reach_another_businesss_product(): void
    {
        $theirs = Item::withoutGlobalScopes()->create([
            'company_id' => $this->rival->id, 'name' => 'Their cement', 'sku' => 'X',
            'type' => 'product', 'price' => 1, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        app(DeliveryReceiver::class)->receive($this->company, [
            ['item_id' => $theirs->id, 'quantity' => 5, 'unit_cost' => 100],
        ], [], $this->owner);
    }

    public function test_a_delivery_cannot_be_booked_into_another_businesss_location(): void
    {
        $item = Item::create([
            'company_id' => $this->company->id, 'name' => 'Cement', 'sku' => 'C',
            'type' => 'product', 'price' => 1, 'track_stock' => true, 'is_active' => true,
        ]);

        $theirLocation = StockLocation::withoutGlobalScopes()->create([
            'company_id' => $this->rival->id, 'name' => 'Their warehouse',
            'kind' => 'warehouse', 'active' => true,
        ]);

        app(DeliveryReceiver::class)->receive($this->company, [
            ['item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 100],
        ], ['location_id' => $theirLocation->id], $this->owner);

        // The unknown location is dropped, not honoured: the movement stays in
        // this company and lands unattributed rather than on a rival's shelf.
        $movement = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->firstOrFail();

        $this->assertNull($movement->stock_location_id);
    }

    public function test_an_invoice_from_another_business_cannot_be_credited(): void
    {
        app(CurrentCompany::class)->set($this->rival);
        ChartOfAccounts::seed($this->rival);
        $theirs = $this->issuedInvoice($this->rival, $this->rivalOwner);

        app(CurrentCompany::class)->set($this->company);

        // The tenant scope is what stands between a crafted id and somebody
        // else's books: the document simply is not found from here.
        $this->assertNull(Document::query()->find($theirs->id));
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function makeCompany(string $prefix, User $owner): Company
    {
        $company = Company::create([
            'slug' => $prefix.'-'.Str::lower(Str::random(4)),
            'name' => ucfirst($prefix).' Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($company, $owner, Role::OWNER);
        $owner->forceFill(['current_company_id' => $company->id])->save();

        return $company;
    }

    protected function invite(string $email): User
    {
        return app(TeamInvitations::class)->invite(
            $this->company, $email, Role::where('slug', Role::SALES_OFFICER)->firstOrFail(), null, $this->owner
        );
    }

    protected function tokenFor(User $user): string
    {
        return DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $user->id)
            ->value('invitation_token');
    }

    protected function issuedInvoice(Company $company, User $actor): Document
    {
        $contact = Contact::create(['name' => 'Someone', 'balance' => 0]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 1000, 'total' => 1000, 'balance' => 1000,
        ]);

        DocumentLine::create([
            'document_id' => $document->id, 'description' => 'x',
            'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000,
        ]);

        return app(DocumentIssuer::class)->issue($document, $actor);
    }
}
