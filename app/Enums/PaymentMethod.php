<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileMoney = 'mobile_money';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::MobileMoney => 'Mobile Money',
            self::Card => 'Card',
        };
    }

    /** Methods that settle instantly and can be receipted on the spot. */
    public function isImmediate(): bool
    {
        return in_array($this, [self::Cash, self::MobileMoney, self::Card], true);
    }
}
