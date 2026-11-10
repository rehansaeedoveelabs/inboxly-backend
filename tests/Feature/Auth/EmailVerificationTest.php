<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_user_can_verify_email_with_signed_link(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_wrong_hash_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user, sha1('someone-else@example.com')))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unsigned_or_tampered_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();
        $this->getJson($this->link($user).'x')->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->spa()->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_is_noop_for_verified_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->spa()->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    }

    public function test_verification_notification_links_to_signed_api_route(): void
    {
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmail)->toMail($user);

        $this->assertStringContainsString('/api/v1/auth/email/verify/'.$user->id.'/', $mail->actionUrl);
        $this->assertStringContainsString('signature=', $mail->actionUrl);
    }
}
