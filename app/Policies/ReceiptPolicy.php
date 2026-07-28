<?php

namespace App\Policies;

class ReceiptPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'receipts';
    }
}
