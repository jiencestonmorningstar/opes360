<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\LoyaltyLedger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * Staff actions on a customer's loyalty card. Redemption usually arrives
 * from the public verification page — the till scans the customer's card,
 * sees the balance, and redeems on the spot — the same shape as ticket
 * check-in.
 */
class LoyaltyController extends Controller
{
    use AuthorizesRequests;

    public function issueCard(Request $request, Contact $contact, LoyaltyLedger $loyalty)
    {
        $this->authorize('update', $contact);
        $this->authorize('loyalty.manage');

        $loyalty->issueCard($contact);

        return back()->with('status', 'Loyalty card issued.');
    }

    public function redeem(Request $request, Contact $contact, LoyaltyLedger $loyalty)
    {
        $this->authorize('view', $contact);
        $this->authorize('loyalty.redeem');

        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $loyalty->redeem($contact, $data['points'], $data['note'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['points' => $e->getMessage()]);
        }

        return back()->with('status', $data['points'].' point'.($data['points'] === 1 ? '' : 's').' redeemed.');
    }

    public function adjust(Request $request, Contact $contact, LoyaltyLedger $loyalty)
    {
        $this->authorize('update', $contact);
        $this->authorize('loyalty.manage');

        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0'],
            'note' => ['required', 'string', 'max:160'],
        ]);

        try {
            $loyalty->adjust($contact, $data['points'], $data['note'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['points' => $e->getMessage()]);
        }

        return back()->with('status', 'Balance adjusted.');
    }
}
