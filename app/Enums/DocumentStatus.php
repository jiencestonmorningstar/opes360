<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Partial => 'Partial',
            self::Paid => 'Paid',
            self::Void => 'Void',
            self::Expired => 'Expired',
        };
    }

    /**
     * Everything except Draft is frozen. Corrections happen by voiding and
     * reissuing, or via a credit note — never by editing an issued document.
     * See docs/architecture/decisions.md #6.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    /** Anything still owed money sits in one of these. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Issued, self::Sent, self::Accepted, self::Partial], true);
    }

    /** Token consumed by the UI to pick a dot/badge colour. */
    public function tone(): string
    {
        return match ($this) {
            self::Paid, self::Accepted => 'positive',
            self::Partial, self::Issued, self::Sent => 'warning',
            self::Void, self::Expired => 'muted',
            self::Draft => 'neutral',
        };
    }
}
