<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * TOTP two-factor state, shared by any Authenticatable with the three
 * two_factor_* columns — User and PlatformAdmin both use it. See
 * App\Services\TwoFactor for enrolment/verification; this trait only reads
 * and reports state, it never mutates it.
 */
trait HasTwoFactorAuthentication
{
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /** Decrypts the TOTP secret, tolerating a key rotation rather than throwing. */
    public function twoFactorSecret(): ?string
    {
        if (blank($this->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->two_factor_secret);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return array<int, string> */
    public function recoveryCodes(): array
    {
        if (blank($this->two_factor_recovery_codes)) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->two_factor_recovery_codes), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }
}
