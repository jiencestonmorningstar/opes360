<?php

namespace Tests\Feature;

use App\Livewire\Settings\Billing;
use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\SubscriptionPaymentSucceededNotification;
use App\Services\Billing\MobileMoneyGateways;
use App\Services\Billing\MtnMomoGateway;
use App\Services\Billing\OrangeMoneyGateway;
use App\Services\Billing\SubscriptionBiller;
use App\Support\CurrentCompany;
use App\Support\PlanEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mtn_momo.base_url' => 'https://sandbox.momodeveloper.mtn.com',
            'services.mtn_momo.environment' => 'sandbox',
            'services.mtn_momo.subscription_key' => 'test-subscription-key',
            'services.mtn_momo.api_user' => 'test-api-user',
            'services.mtn_momo.api_key' => 'test-api-key',
            'services.mtn_momo.default_country_code' => '237',
            'services.orange_money.base_url' => 'https://api.orange.com',
            'services.orange_money.oauth_path' => '/oauth/v3/token',
            'services.orange_money.client_id' => 'test-client-id',
            'services.orange_money.client_secret' => 'test-client-secret',
            'services.orange_money.merchant_key' => 'test-merchant-key',
            'services.orange_money.country' => 'cm',
        ]);

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'basic',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);
    }

    protected function pendingPayment(string $provider, string $plan = 'basic', string $cycle = 'monthly'): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'company_id' => $this->company->id,
            'initiated_by' => $this->owner->id,
            'plan' => $plan,
            'billing_cycle' => $cycle,
            'amount' => PlanEntitlements::priceFor($plan, $cycle),
            'currency' => 'XAF',
            'provider' => $provider,
            'phone' => '670416238',
            'external_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);
    }

    public function test_annual_price_is_ten_times_monthly(): void
    {
        $this->assertSame(3000, PlanEntitlements::priceFor('basic', 'monthly'));
        $this->assertSame(30000, PlanEntitlements::priceFor('basic', 'annual'));
        $this->assertSame(21000, PlanEntitlements::priceFor('business', 'monthly'));
    }

    public function test_available_gateways_reflect_configured_credentials(): void
    {
        $keys = array_column(MobileMoneyGateways::available(), 'key');
        $this->assertContains('mtn_momo', $keys);
        $this->assertContains('orange_money', $keys);

        config(['services.orange_money.merchant_key' => null]);

        $keys = array_column(MobileMoneyGateways::available(), 'key');
        $this->assertContains('mtn_momo', $keys);
        $this->assertNotContains('orange_money', $keys);
    }

    public function test_mtn_gateway_initiate_normalises_local_phone_and_sends_reference(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        $payment = $this->pendingPayment('mtn_momo', 'growth');

        $result = app(MtnMomoGateway::class)->initiate($payment);

        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['redirect_url']);

        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'https://sandbox.momodeveloper.mtn.com/collection/v1_0/requesttopay'
                && $request->hasHeader('X-Reference-Id', $payment->external_id)
                && $request['payer']['partyId'] === '237670416238'
                && $request['currency'] === 'XAF';
        });
    }

    public function test_mtn_gateway_initiate_failure_is_reported_without_throwing(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response(['message' => 'Payer not found'], 400),
        ]);

        $payment = $this->pendingPayment('mtn_momo');

        $result = app(MtnMomoGateway::class)->initiate($payment);

        $this->assertSame('failed', $result['status']);
        $this->assertNotNull($result['message']);
    }

    public function test_mtn_gateway_check_status_maps_provider_states(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'financialTransactionId' => 'FT1'], 200),
        ]);

        $payment = $this->pendingPayment('mtn_momo');

        $result = app(MtnMomoGateway::class)->checkStatus($payment);

        $this->assertSame('successful', $result['status']);
        $this->assertSame('FT1', $result['provider_reference']);
    }

    public function test_orange_gateway_initiate_returns_a_redirect_url(): void
    {
        Http::fake([
            '*/oauth/v3/token' => Http::response(['access_token' => 'tok-2', 'expires_in' => 600], 200),
            '*/orange-money-webpay/cm/v1/webpayment' => Http::response([
                'status' => '201',
                'payment_url' => 'https://webpayment.orange-money.com/abc',
                'pay_token' => 'PTOKEN1',
            ], 200),
        ]);

        $payment = $this->pendingPayment('orange_money');

        $result = app(OrangeMoneyGateway::class)->initiate($payment);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('https://webpayment.orange-money.com/abc', $result['redirect_url']);
        $this->assertSame('PTOKEN1', $result['provider_reference']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orange-money-webpay/cm/v1/webpayment')
            && $request['order_id'] === $payment->external_id
            && $request['merchant_key'] === 'test-merchant-key');
    }

    public function test_orange_gateway_check_status_maps_provider_states(): void
    {
        Http::fake([
            '*/oauth/v3/token' => Http::response(['access_token' => 'tok-2', 'expires_in' => 600], 200),
            '*/orange-money-webpay/cm/v1/transactionstatus' => Http::response(['status' => 'FAILED', 'txnid' => 'TX1'], 200),
        ]);

        $payment = $this->pendingPayment('orange_money');
        $payment->update(['provider_reference' => 'PTOKEN1']);

        $result = app(OrangeMoneyGateway::class)->checkStatus($payment);

        $this->assertSame('failed', $result['status']);
    }

    public function test_biller_start_creates_a_pending_payment_with_computed_amount(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        $result = app(SubscriptionBiller::class)->start(
            $this->company, $this->owner, 'growth', 'annual', 'mtn_momo', '670416238'
        );

        $payment = $result['payment'];

        $this->assertSame('pending', $payment->status);
        $this->assertSame('90000.00', (string) $payment->amount);
        $this->assertSame('XAF', $payment->currency);
        $this->assertSame($this->owner->id, $payment->initiated_by);
    }

    public function test_biller_activates_the_plan_exactly_once_on_success(): void
    {
        Notification::fake();

        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response('', 202),
            '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'financialTransactionId' => 'FT9'], 200),
        ]);

        $biller = app(SubscriptionBiller::class);

        $result = $biller->start($this->company, $this->owner, 'business', 'annual', 'mtn_momo', '670416238');
        $payment = $result['payment'];

        $updated = $biller->refresh($payment);

        $this->assertSame('successful', $updated->status);
        $this->assertNotNull($updated->paid_at);

        $this->company->refresh();
        $this->assertSame('business', $this->company->plan);
        $this->assertSame('active', $this->company->account_type);
        $this->assertTrue($this->company->plan_renews_at->greaterThan(now()->addMonths(11)));

        Notification::assertSentTo($this->owner, SubscriptionPaymentSucceededNotification::class);

        // A second refresh (a duplicate webhook, a stale poll) must not
        // re-activate the plan or send a second receipt.
        $again = $biller->refresh($updated);
        $this->assertSame('successful', $again->status);
        Notification::assertSentToTimes($this->owner, SubscriptionPaymentSucceededNotification::class, 1);
    }

    public function test_mtn_webhook_reverifies_with_the_provider_rather_than_trusting_the_body(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response('', 202),
            '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL'], 200),
        ]);

        $result = app(SubscriptionBiller::class)->start($this->company, $this->owner, 'growth', 'monthly', 'mtn_momo', '670416238');
        $payment = $result['payment'];

        // A forged callback claiming success should still only succeed
        // because our own re-check (faked SUCCESSFUL above) says so.
        $this->postJson('/webhooks/mtn-momo', ['externalId' => $payment->external_id, 'status' => 'SUCCESSFUL'])
            ->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('growth', $this->company->fresh()->plan);
    }

    public function test_mtn_webhook_for_an_unknown_reference_is_ignored_safely(): void
    {
        $this->postJson('/webhooks/mtn-momo', ['externalId' => 'no-such-payment'])->assertOk();
    }

    public function test_orange_return_route_reverifies_and_redirects_to_billing(): void
    {
        Http::fake([
            '*/oauth/v3/token' => Http::response(['access_token' => 'tok-2', 'expires_in' => 600], 200),
            '*/orange-money-webpay/cm/v1/webpayment' => Http::response(['payment_url' => 'https://pay.example/x', 'pay_token' => 'PT1'], 200),
            '*/orange-money-webpay/cm/v1/transactionstatus' => Http::response(['status' => 'SUCCESS', 'txnid' => 'TX1'], 200),
        ]);

        $result = app(SubscriptionBiller::class)->start($this->company, $this->owner, 'growth', 'monthly', 'orange_money');
        $payment = $result['payment'];

        $response = $this->actingAs($this->owner)->get('/billing/orange/return?ref='.$payment->external_id);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHas('billingStatus');
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_billing_page_starts_a_mtn_payment(): void
    {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            '*/collection/v1_0/requesttopay' => Http::response('', 202),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Billing::class)
            ->set('plan', 'growth')
            ->set('billingCycle', 'monthly')
            ->set('provider', 'mtn_momo')
            ->set('phone', '670416238')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscription_payments', [
            'company_id' => $this->company->id,
            'plan' => 'growth',
            'provider' => 'mtn_momo',
            'status' => 'pending',
        ]);
    }

    public function test_billing_page_redirects_away_for_orange_money(): void
    {
        Http::fake([
            '*/oauth/v3/token' => Http::response(['access_token' => 'tok-2', 'expires_in' => 600], 200),
            '*/orange-money-webpay/cm/v1/webpayment' => Http::response(['payment_url' => 'https://pay.example/y', 'pay_token' => 'PT2'], 200),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Billing::class)
            ->set('plan', 'basic')
            ->set('billingCycle', 'monthly')
            ->set('provider', 'orange_money')
            ->call('pay')
            ->assertRedirect('https://pay.example/y');
    }

    public function test_billing_page_requires_a_phone_number_for_mtn(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Billing::class)
            ->set('provider', 'mtn_momo')
            ->set('phone', '')
            ->call('pay')
            ->assertHasErrors(['phone']);
    }

    public function test_non_owner_cannot_open_the_billing_page(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        $this->actingAs($cashier)->get(route('settings.billing'))->assertForbidden();
    }

    public function test_non_owner_cannot_start_a_payment_even_if_the_component_is_reached_directly(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        Livewire::actingAs($cashier)
            ->test(Billing::class)
            ->set('provider', 'mtn_momo')
            ->set('phone', '670416238')
            ->call('pay')
            ->assertForbidden();
    }
}
