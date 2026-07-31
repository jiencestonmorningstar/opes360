<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * Tickets share the events permission group: anyone who can run the door for
 * an event acts on its tickets, and a separate grant would only drift.
 */
class TicketPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'events';
    }

    public function checkIn(User $user, Ticket $ticket): bool
    {
        return $this->owns($ticket) && $this->allows($user, 'check-in');
    }

    public function void(User $user, Ticket $ticket): bool
    {
        return $this->owns($ticket) && $this->allows($user, 'void');
    }
}
