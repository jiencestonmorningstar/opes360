<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Models\VerificationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * All loyalty-points state changes go through here, never a direct update to
 * contacts.loyalty_points — the same reasoning as the stock ledger: a cached
 * balance updated in place cannot explain itself later, a ledger row can.
 */
class LoyaltyLedger
{
    /** Card numbers are short enough to read aloud at a till. */
    public function issueCard(Contact $contact): Contact
    {
        if ($contact->hasLoyaltyCard()) {
            return $contact;
        }

        return DB::transaction(function () use ($contact) {
            $token = VerificationToken::create([
                'company_id' => $contact->company_id,
                'token' => VerificationToken::newToken(),
                'subject_type' => Contact::class,
                'subject_id' => $contact->id,
            ]);

            $contact->forceFill([
                'loyalty_card_number' => $this->newCardNumber($contact->company_id),
                'loyalty_card_issued_at' => now(),
                'loyalty_verification_token_id' => $token->id,
            ])->save();

            return $contact->fresh();
        });
    }

    protected function newCardNumber(string $companyId): string
    {
        do {
            $number = 'LOY-'.Str::upper(Str::random(8));
        } while (Contact::withoutGlobalScopes()->where('company_id', $companyId)->where('loyalty_card_number', $number)->exists());

        return $number;
    }

    /**
     * Points earned from a spend, per the company's rate. A no-op (not an
     * error) when the program is off or the spend earns zero — callers like
     * PaymentRecorder should never have to check loyalty_enabled themselves.
     */
    public function earn(Contact $contact, Company $company, float $amountSpent, string $referenceType, string $referenceId, ?User $actor = null): ?LoyaltyTransaction
    {
        if (! $company->loyalty_enabled) {
            return null;
        }

        $points = $company->loyaltyPointsFor($amountSpent);

        if ($points <= 0) {
            return null;
        }

        return $this->record($contact, 'earn', $points, $referenceType, $referenceId, null, $actor);
    }

    public function redeem(Contact $contact, int $points, ?string $note, User $actor): LoyaltyTransaction
    {
        if ($points <= 0) {
            throw new RuntimeException('Redeem an amount greater than zero.');
        }

        if ($points > $contact->loyalty_points) {
            throw new RuntimeException(sprintf(
                '%s has only %d point%s available.',
                $contact->displayName(),
                $contact->loyalty_points,
                $contact->loyalty_points === 1 ? '' : 's',
            ));
        }

        return $this->record($contact, 'redeem', -$points, null, null, $note, $actor);
    }

    public function adjust(Contact $contact, int $points, string $note, User $actor): LoyaltyTransaction
    {
        if ($points === 0) {
            throw new RuntimeException('An adjustment must be a non-zero number of points.');
        }

        if ($contact->loyalty_points + $points < 0) {
            throw new RuntimeException('That adjustment would take the balance below zero.');
        }

        return $this->record($contact, 'adjust', $points, null, null, $note, $actor);
    }

    protected function record(
        Contact $contact,
        string $type,
        int $points,
        ?string $referenceType,
        ?string $referenceId,
        ?string $note,
        ?User $actor,
    ): LoyaltyTransaction {
        return DB::transaction(function () use ($contact, $type, $points, $referenceType, $referenceId, $note, $actor) {
            // Locked so two concurrent earns (e.g. two tills) cannot both read
            // the same starting balance and silently drop one of them.
            $locked = Contact::query()->whereKey($contact->id)->lockForUpdate()->first();

            $balance = max(0, $locked->loyalty_points + $points);

            $transaction = LoyaltyTransaction::create([
                'company_id' => $locked->company_id,
                'contact_id' => $locked->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'created_by' => $actor?->id,
            ]);

            $locked->forceFill(['loyalty_points' => $balance])->save();
            $contact->loyalty_points = $balance;

            return $transaction;
        });
    }
}
