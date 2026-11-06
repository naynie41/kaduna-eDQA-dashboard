<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\NewDeviceLogin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §2: a sign-in from an IP + browser pair not seen for that administrator in 90 days
// emails them and the other active administrators, and is audited. Only hashed fingerprints are
// stored.

const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function signIn(mixed $test, User $user, string $ip = '203.0.113.20', string $agent = BROWSER): void
{
    $test->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $agent)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $test->assertAuthenticatedAs($user);
    auth()->logout();
}

beforeEach(function (): void {
    Notification::fake();
    $this->user = User::factory()->create(['name' => 'Amina Bello']);
    $this->other = User::factory()->create();
    $this->disabled = User::factory()->create(['is_active' => false]);
});

it('emails that administrator and the other active ones on a new device, queued', function (): void {
    signIn($this, $this->user);

    Notification::assertSentTo([$this->user, $this->other], NewDeviceLogin::class);
    Notification::assertNotSentTo($this->disabled, NewDeviceLogin::class);
    expect(new NewDeviceLogin($this->user, '203.0.113.20', BROWSER, now()))->toBeInstanceOf(ShouldQueue::class);
});

it('says when (WAT), from where and with which browser', function (): void {
    Carbon::setTestNow('2026-11-06 09:30:00 UTC');
    signIn($this, $this->user);

    Notification::assertSentTo($this->other, NewDeviceLogin::class, function (NewDeviceLogin $notification) {
        $mail = $notification->toMail($this->other);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

        return str_contains($text, 'Amina Bello')
            && str_contains($text, '6 Nov 2026, 10:30 WAT')
            && str_contains($text, '203.0.113.20')
            && str_contains($text, 'Chrome 130 on Windows');
    });
});

it('does not email for a device seen in the last 90 days', function (): void {
    signIn($this, $this->user);
    Notification::fake();

    $this->travel(89)->days();
    signIn($this, $this->user);

    Notification::assertNothingSent();
});

it('emails again once the device has not been seen for 90 days', function (): void {
    signIn($this, $this->user);
    Notification::fake();

    $this->travel(91)->days();
    signIn($this, $this->user);

    Notification::assertSentTo($this->user, NewDeviceLogin::class);
});

it('treats a new browser or a new address as a new device', function (string $ip, string $agent): void {
    signIn($this, $this->user);
    Notification::fake();

    signIn($this, $this->user, $ip, $agent);

    Notification::assertSentTo($this->user, NewDeviceLogin::class);
})->with([
    'new address' => ['198.51.100.7', BROWSER],
    'new browser' => ['203.0.113.20', 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0'],
]);

it('stores a hashed fingerprint, never the address or the browser', function (): void {
    signIn($this, $this->user);

    $row = DB::table('user_devices')->where('user_id', $this->user->id)->sole();
    expect($row->fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($row))->not->toContain('203.0.113.20')
        ->not->toContain('Chrome');
});

it('audits the new-device sign-in', function (): void {
    signIn($this, $this->user);

    $entry = Activity::query()->where('log_name', 'auth')->where('event', 'login-new-device')->sole();
    expect($entry->subject?->is($this->user))->toBeTrue()
        ->and($entry->properties['ip'])->toBe('203.0.113.20')
        ->and($entry->properties['user_agent'])->toBe(BROWSER)
        ->and($entry->properties['notified'])->toBe(2);
});
