<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Scopes\CompanyScope;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Service\TicketDesk;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Walk-in triage — the page behind the QR on the counter.
 *
 * A customer standing in the doorway scans the business's printed code,
 * says what is wrong, and walks away holding a queue number. Same tenancy
 * rule as verification and public forms: the visitor is not a user, the
 * token names the company, and nothing is ever resolved across tenants.
 *
 * The QR is the company's existing verification token — the one already on
 * the stationery. This page is a second door behind the same code, not a
 * second code.
 */
class TriagePublicController extends Controller
{
    use AbortsForSuspendedCompany;

    /** A list short enough to read while standing in a doorway. */
    public const CATEGORIES = [
        'repair' => 'Something is broken',
        'billing' => 'A bill or a payment',
        'order' => 'An order or a delivery',
        'other' => 'Something else',
    ];

    /**
     * How urgent it feels to the customer, in the customer's words — mapped
     * to the desk's priorities conservatively. "Right now" becomes at most
     * `high`: `urgent` carries an SLA promise, and that promise is the
     * business's to make, not the visitor's. Staff escalate in the queue as
     * normal when they agree.
     */
    public const URGENCY = [
        'whenever' => 'low',
        'today' => 'normal',
        'now' => 'high',
    ];

    public function show(string $token)
    {
        [$verification, $company] = $this->resolve($token);

        return view('public.triage', [
            'company' => $company,
            'token' => $verification->token,
            'closed' => ! Modules::enabled($company, 'service'),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function submit(Request $request, string $token)
    {
        [$verification, $company] = $this->resolve($token);

        if (! Modules::enabled($company, 'service')) {
            // The page renders its own "desk is closed" state.
            return redirect()->to('/triage/'.$token);
        }

        // Per-token limit on top of the route's per-IP throttle: a crowd of
        // addresses hammering one business's code is still one business's
        // queue being flooded.
        $key = 'triage:'.$verification->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            abort(429);
        }

        RateLimiter::hit($key, 3600);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'urgency' => ['required', 'string', 'in:'.implode(',', array_keys(self::URGENCY))],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        return app(CurrentCompany::class)->as($company, function () use ($company, $token, $validated) {
            $contact = $this->matchContact($validated['phone']);

            $ticket = app(TicketDesk::class)->open([
                'subject' => 'Walk-in: '.self::CATEGORIES[$validated['category']],
                'description' => $validated['description'],
                'channel' => 'walk_in',
                'category' => $validated['category'],
                'priority' => self::URGENCY[$validated['urgency']],
                'contact_id' => $contact?->id,
                // Matching, never minting: an unauthenticated page must not be
                // able to fill the customer book, so an unknown visitor rides
                // on the ticket itself until staff promote them.
                'visitor_name' => $contact === null ? $validated['name'] : null,
                'visitor_phone' => $contact === null ? $validated['phone'] : null,
            ], $this->deskActor($company));

            return redirect()->to('/triage/'.$token.'/done?t='.urlencode($ticket->reference));
        });
    }

    public function done(Request $request, string $token)
    {
        [, $company] = $this->resolve($token);

        return app(CurrentCompany::class)->as($company, function () use ($request, $company, $token) {
            $ticket = ServiceTicket::query()
                ->where('reference', (string) $request->query('t'))
                ->where('channel', 'walk_in')
                ->first();

            if ($ticket === null) {
                return redirect()->to('/triage/'.$token);
            }

            // Their place in the line: open walk-ins that arrived before them.
            $ahead = ServiceTicket::query()
                ->where('channel', 'walk_in')
                ->whereNotIn('status', ServiceTicket::SETTLED)
                ->where('opened_at', '<', $ticket->opened_at)
                ->where('id', '!=', $ticket->id)
                ->count();

            return view('public.triage-done', [
                'company' => $company,
                'ticket' => $ticket,
                'ahead' => $ahead,
            ]);
        });
    }

    /**
     * The company behind the code — and only a *company* code. A receipt or
     * invoice token resolves on /v/ but must not open the triage door: the
     * two pages make different promises about what the token proves.
     *
     * @return array{0: VerificationToken, 1: Company}
     */
    protected function resolve(string $token): array
    {
        $verification = VerificationToken::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('token', $token)
            ->where('subject_type', Company::class)
            ->first();

        if ($verification === null || $verification->isRevoked()) {
            abort(404);
        }

        $company = Company::find($verification->company_id);

        if ($company === null) {
            abort(404);
        }

        $this->abortIfSuspended($company);

        return [$verification, $company];
    }

    /**
     * Match the visitor to the customer book by phone digits, suffix-first —
     * "+237 650 11 22 33" and "650112233" are the same person. Loaded and
     * compared in PHP because `phones` is a JSON column of free-format
     * strings; the set is one company's contacts, not the whole table.
     */
    protected function matchContact(string $phone): ?Contact
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen((string) $digits) < 8) {
            return null;
        }

        $needle = substr($digits, -8);

        return Contact::query()
            ->whereNotNull('phones')
            ->get(['id', 'phones'])
            ->first(function (Contact $contact) use ($needle) {
                foreach ((array) $contact->phones as $stored) {
                    $storedDigits = preg_replace('/\D+/', '', (string) $stored);

                    if ($storedDigits !== '' && str_ends_with($storedDigits, $needle)) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Who the ticket is recorded as opened by. The desk requires an acting
     * user for its audit trail, and a visitor has none — so the opening is
     * attributed to the business owner, the person answerable for the desk.
     */
    protected function deskActor(Company $company): User
    {
        $actor = User::find($company->owner_id)
            ?? $company->users()->orderBy('users.id')->first();

        if ($actor === null) {
            // A business with no members has no desk to hand the ticket to.
            abort(503);
        }

        return $actor;
    }
}
