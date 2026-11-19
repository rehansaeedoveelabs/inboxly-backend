<?php

namespace App\Actions\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class IssueEmailOtp
{
    public function handle(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already verified.'],
            ]);
        }

        $ttlMinutes = max(1, (int) config('auth.email_otp_ttl_minutes', 10));
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $code, $ttlMinutes) {
            EmailOtp::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            EmailOtp::query()->create([
                'user_id' => $user->getKey(),
                'email' => mb_strtolower((string) $user->email),
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });

        $user->notify(new EmailOtpNotification($code));
    }
}
