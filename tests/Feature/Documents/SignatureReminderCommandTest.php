<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentSignature;
use App\Notifications\SignatureReminder;
use App\Services\Documents\DocumentSignatureRequests;
use Illuminate\Support\Facades\Notification;

/**
 * `opes:remind-pending-signatures` — the external-facing half of chasing a
 * slow signer; see the command's own docblock for how it differs from
 * `opes:remind-actions`' internal `document.signature.overdue` event.
 */
class SignatureReminderCommandTest extends DocumentsTestCase
{
    protected function requests(): DocumentSignatureRequests
    {
        return app(DocumentSignatureRequests::class);
    }

    public function test_a_signature_pending_past_the_threshold_is_reminded(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();
        $signature->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures')->assertSuccessful();

        Notification::assertSentOnDemand(
            SignatureReminder::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'a@example.com',
        );
        $this->assertNotNull($signature->fresh()->last_reminded_at);
    }

    public function test_a_signature_still_within_the_grace_window_is_not_reminded(): void
    {
        $paper = $this->document();
        $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']]);

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures');

        Notification::assertNothingSent();
    }

    public function test_a_signature_reminded_recently_is_not_reminded_again(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();
        $signature->forceFill([
            'created_at' => now()->subDays(10),
            'last_reminded_at' => now()->subDay(),
        ])->saveQuietly();

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures');

        Notification::assertNothingSent();
    }

    public function test_a_signature_reminded_long_ago_is_reminded_again(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();
        $signature->forceFill([
            'created_at' => now()->subDays(10),
            'last_reminded_at' => now()->subDays(5),
        ])->saveQuietly();

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures');

        Notification::assertSentOnDemandTimes(SignatureReminder::class, 1);
    }

    public function test_a_signed_or_declined_signature_is_never_reminded(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();
        $signature->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        $this->requests()->sign($signature->fresh());

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures');

        Notification::assertNothingSent();
    }

    public function test_a_sequential_signer_whose_turn_has_not_come_is_not_reminded(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ], 'sequential');
        BusinessDocumentSignature::query()
            ->whereIn('id', $signatures->pluck('id'))
            ->update(['created_at' => now()->subDays(10)]);

        Notification::fake();
        $this->artisan('opes:remind-pending-signatures');

        Notification::assertSentOnDemandTimes(SignatureReminder::class, 1);
        Notification::assertSentOnDemand(
            SignatureReminder::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'a@example.com',
        );
    }
}
