<?php

namespace App\Policies;

class DeliveryNotePolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'orders';
    }
}
