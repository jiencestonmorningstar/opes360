<?php

namespace App\Services;

use App\Contracts\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor enrolment and verification.
 *
 * Secrets and recovery codes are encrypted at rest, so a leaked database row
 * does not hand an attacker a working second factor. Enrolment is only
 * confirmed once the user proves they can generate a code, which stops a
 * half-finished setup from locking anyone out.
 *
 * Works against User or PlatformAdmin — anything that is both an Eloquent
 * model (for forceFill/save) and implements TwoFactorAuthenticatable (for
 * the encrypted-state helpers).
 */
class TwoFactor
{
    public function __construct(protected Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)))
            ->all();
    }

    /** The otpauth:// URI an authenticator app scans. */
    public function provisioningUri(Model&TwoFactorAuthenticatable $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('opes.brand.name'),
            $user->email,
            $secret,
        );
    }

    public function verify(string $secret, string $code): bool
    {
        // A one-window tolerance covers ordinary clock drift between the phone
        // and the server without meaningfully widening the attack surface.
        return $this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    /** Starts enrolment without enabling it — nothing changes until confirmed. */
    public function startEnrolment(Model&TwoFactorAuthenticatable $user): string
    {
        $secret = $this->generateSecret();

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($this->generateRecoveryCodes())),
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    public function confirm(Model&TwoFactorAuthenticatable $user, string $code): bool
    {
        $secret = $user->twoFactorSecret();

        if ($secret === null || ! $this->verify($secret, $code)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    public function disable(Model&TwoFactorAuthenticatable $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Consumes a recovery code, removing it so each works exactly once.
     */
    public function consumeRecoveryCode(Model&TwoFactorAuthenticatable $user, string $code): bool
    {
        $codes = $user->recoveryCodes();
        $code = Str::lower(trim($code));

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $remaining = array_values(array_diff($codes, [$code]));

        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($remaining)),
        ])->save();

        return true;
    }
}
