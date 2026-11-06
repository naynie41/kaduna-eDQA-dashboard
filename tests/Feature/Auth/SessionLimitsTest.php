<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §2: 2 hours idle, 12 hours at most from sign-in, no "remember me" to outlive either,
// and a password change ends the user's other sessions.

function otherSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
}

it('times sessions out after 2 hours idle', function (): void {
    expect(config('session.lifetime'))->toBe(120)
        ->and(file_get_contents(base_path('.env.example')))->toContain("\nSESSION_LIFETIME=120\n");
});

it('ends a session 12 hours after sign-in, however active', function (): void {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->travel(11)->hours();
    $this->get('/admin/sources')->assertOk();

    $this->travel(61)->minutes();
    $this->get('/admin/sources')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('answers 401 to a JSON request on an expired session', function (): void {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->travel(12 * 60 + 1)->minutes();
    $this->getJson('/admin/api/sources')->assertUnauthorized();
    $this->assertGuest();
});

it('records the logout when the 12 hours are up', function (): void {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->travel(13)->hours();
    $this->get('/admin/sources');

    $entry = Activity::query()->where('log_name', 'auth')->where('event', 'logout')->sole();
    expect($entry->causer?->is($user))->toBeTrue()
        ->and($entry->properties['reason'])->toBe('session_expired');
});

it('starts the 12 hours for a session that predates the limit at its next request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/sources')->assertOk();
    $this->travel(12 * 60 + 1)->minutes();
    $this->get('/admin/sources')->assertRedirect(route('login'));
});

it('never issues a remember-me cookie', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $this->assertAuthenticatedAs($user);
    $names = collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName());
    expect($names->filter(fn (string $name): bool => str_starts_with($name, 'remember_'))->all())->toBe([]);
});

it('ends the user\'s other sessions when they change their password, keeping this one', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $colleague = User::factory()->create();
    otherSession($user, 'laptop-session');
    otherSession($user, 'phone-session');
    otherSession($colleague, 'colleague-session');

    $this->actingAs($user)->from(route('admin.security.edit'))->put(route('admin.user-password.update'), [
        'current_password' => 'password',
        'password' => 'First-Password-01',
        'password_confirmation' => 'First-Password-01',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->whereIn('id', ['laptop-session', 'phone-session'])->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'colleague-session')->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe('old-remember-token');
    $this->assertAuthenticatedAs($user);
});

it('ends every session of the user on a password reset', function (): void {
    config(['session.driver' => 'database']);
    Notification::fake();
    $user = User::factory()->create();
    otherSession($user, 'laptop-session');

    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token, 'email' => $user->email,
            'password' => 'First-Password-01', 'password_confirmation' => 'First-Password-01',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});
