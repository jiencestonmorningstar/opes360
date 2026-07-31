<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Staff actions on tickets. Check-in usually arrives from the public
 * verification page — the scanner is a logged-in member of the company whose
 * event it is, so the policy passes; for anyone else the button never renders
 * and the POST is refused.
 */
class TicketController extends Controller
{
    use AuthorizesRequests;

    public function checkIn(Request $request, Ticket $ticket)
    {
        $this->authorize('checkIn', $ticket);

        if ($ticket->isVoid()) {
            return back()->withErrors(['ticket' => 'This ticket is void and cannot be checked in.']);
        }

        if ($ticket->isCheckedIn()) {
            return back()->withErrors(['ticket' => 'This ticket was already checked in.']);
        }

        $ticket->forceFill([
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_in_by' => $request->user()->id,
        ])->save();

        return back()->with('status', 'Checked in — '.$ticket->buyer_name.'.');
    }

    public function void(Request $request, Ticket $ticket)
    {
        $this->authorize('void', $ticket);

        if (! $ticket->isVoid()) {
            $ticket->forceFill(['status' => 'void'])->save();
        }

        return back()->with('status', 'Ticket voided.');
    }

    public function togglePaid(Request $request, Ticket $ticket)
    {
        $this->authorize('update', $ticket->event);

        $ticket->forceFill(['paid_at' => $ticket->paid_at === null ? now() : null])->save();

        return back();
    }
}
