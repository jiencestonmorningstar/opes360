<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;

/**
 * Suspension has to reach the public surface, not just the sign-in screen.
 *
 * SetCurrentCompany turns a suspended business's own users away, but it never
 * runs for a guest — so until this existed, a business suspended for
 * non-payment or abuse carried on selling tickets, collecting form responses
 * and serving its profile page to the world. The only thing suspension
 * actually stopped was the owner logging in to watch it happen.
 *
 * Verification is deliberately *not* covered by this. Someone holding a
 * printed receipt is a third party to whatever dispute got the business
 * suspended, and taking away their ability to check that receipt punishes the
 * customer for the merchant's conduct. Documents already in someone's hands
 * stay verifiable.
 *
 * 404 rather than 403: a suspended business should not be distinguishable
 * from one that never existed, and it matches what an unknown token returns.
 */
trait AbortsForSuspendedCompany
{
    protected function abortIfSuspended(?Company $company): void
    {
        abort_if((bool) $company?->isSuspended(), 404);
    }
}
