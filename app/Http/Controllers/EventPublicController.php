<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Services\SoldOutException;
use App\Services\TicketSeller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * The public face of Opes Events — the sales page a share link or poster QR
 * opens, and the confirmation page that holds the buyer's tickets.
 */
class EventPublicController extends Controller
{
    public function show(string $token)
    {
        $event = $this->findEvent($token);

        if ($event === null || $event->status === 'draft') {
            abort(404);
        }

        return view('public.event', [
            'event' => $event,
            'company' => $event->company,
            'types' => $event->ticketTypes,
        ]);
    }

    /**
     * The iframe variant — an event card for embedding on another website.
     *
     * Unlike forms, the purchase itself does not happen inside the frame: the
     * confirmation page holds the buyer's tickets in the session, and inside
     * a third-party iframe the browser strips that cookie — the buyer would
     * pay attention, submit, and land with nothing. The embed therefore sells
     * the click, and "Get tickets" opens the full page as its own tab.
     */
    public function embed(string $token)
    {
        $event = $this->findEvent($token);

        if ($event === null || $event->status === 'draft') {
            abort(404);
        }

        return view('public.event-embed', [
            'event' => $event,
            'company' => $event->company,
            'types' => $event->ticketTypes,
        ]);
    }

    public function purchase(Request $request, string $token, TicketSeller $seller)
    {
        $event = $this->findEvent($token);

        if ($event === null || $event->status === 'draft') {
            abort(404);
        }

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['nullable', 'email', 'max:255', 'required_without:buyer_phone'],
            'buyer_phone' => ['nullable', 'string', 'max:40', 'required_without:buyer_email'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:10'],
        ], [
            'buyer_email.required_without' => 'Leave an email or a phone number so the tickets can reach you.',
            'buyer_phone.required_without' => 'Leave an email or a phone number so the tickets can reach you.',
        ]);

        try {
            $tickets = $seller->sell(
                $event,
                $data['quantities'],
                $data['buyer_name'],
                $data['buyer_email'] ?? null,
                $data['buyer_phone'] ?? null,
            );
        } catch (SoldOutException $e) {
            throw ValidationException::withMessages(['quantities' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['quantities' => 'Choose at least one ticket.']);
        }

        // The confirmation page is reached by session, not by URL: a link that
        // listed ticket ids would hand anyone who saw it someone else's tickets.
        $request->session()->put('purchased_tickets', collect($tickets)->pluck('id')->all());

        return redirect()->to('/e/'.$token.'/tickets');
    }

    public function tickets(Request $request, string $token)
    {
        $event = $this->findEvent($token);

        if ($event === null) {
            abort(404);
        }

        $ids = $request->session()->get('purchased_tickets', []);

        $tickets = Ticket::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['ticketType', 'verificationToken'])
            ->whereKey($ids)
            ->where('event_id', $event->id)
            ->get();

        if ($tickets->isEmpty()) {
            return redirect()->to('/e/'.$token);
        }

        return view('public.event-tickets', [
            'event' => $event,
            'company' => $event->company,
            'tickets' => $tickets,
        ]);
    }

    protected function findEvent(string $token): ?Event
    {
        return Event::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company')
            ->where('share_token', $token)
            ->first();
    }
}
