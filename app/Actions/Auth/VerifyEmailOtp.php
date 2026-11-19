<?php

namespace App\Actions\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyEmailOtp
{
    public function handle(User $user, string $code): User
    {
        if ($user->hasVerifiedEmail()) {
            return $user;
        }

        $maxAttempts = max(1, (int) config('auth.email_otp_max_attempts', 5));
        $normalized = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $normalized)) {
            throw ValidationException::withMessages([
                'code' => ['Enter the 6-digit verification code.'],
            ]);
        }

        DB::transaction(function () use ($user, $normalized, $maxAttempts) {
            /** @var EmailOtp|null $otp */
            $otp = EmailOtp::query()
                ->where('user_id', $user->getKey())
                ->where('email', mb_strtolower((string) $user->email))
                ->whereNull('consumed_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->isExpired()) {
                throw ValidationException::withMessages([
                    'code' => ['This verification code is invalid or has expired.'],
                ]);
            }

            if ((int) $otp->attempts >= $maxAttempts) {
                $otp->forceFill(['consumed_at' => now()])->save();
                throw ValidationException::withMessages([
                    'code' => ['Too many incorrect attempts. Request a new code.'],
                ]);
            }

            if (! Hash::check($normalized, $otp->code_hash)) {
                $otp->forceFill(['attempts' => (int) $otp->attempts + 1])->save();
                throw ValidationException::withMessages([
                    'code' => ['That verification code is incorrect.'],
                ]);
            }

            $otp->forceFill(['consumed_at' => now()])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
        });

        return $user->fresh() ?? $user;
    }
}
