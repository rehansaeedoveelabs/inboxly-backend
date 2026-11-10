<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_and_points_to_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password?token=');
        });
    }

    public function test_unknown_email_gets_same_response_and_no_notification(): void
    {
        Notification::fake();

        $known = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => User::factory()->create()->email]);
        $unknown = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'fresh-password-1',
            'password_confirmation' => 'fresh-password-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('fresh-password-1', $user->fresh()->password));
        Event::assertDispatched(PasswordReset::class);

        // Token is single use.
        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-password-1',
            'password_confirmation' => 'another-password-1',
        ])->assertUnprocessable();
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'fresh-password-1',
            'password_confirmation' => 'fresh-password-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
