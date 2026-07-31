<?php

namespace App\Contracts;

/**
 * Implemented by User and PlatformAdmin (via the HasTwoFactorAuthentication
 * trait) so App\Services\TwoFactor can enrol/verify either without knowing
 * which one it's holding.
 */
interface TwoFactorAuthenticatable
{
    public function hasTwoFactorEnabled(): bool;

    public function twoFactorSecret(): ?string;

    /** @return array<int, string> */
    public function recoveryCodes(): array;
}
