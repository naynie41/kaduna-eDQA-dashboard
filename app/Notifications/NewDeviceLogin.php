<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\Auth\BrowserName;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "An administrator signed in from a new device" (SECURITY.md §2), to that administrator and the
 * other active ones: when (WAT), from which IP and with which browser. Queued.
 */
final class NewDeviceLogin extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly string $ip,
        public readonly ?string $userAgent,
        public readonly CarbonInterface $at,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $self = $notifiable instanceof User && $notifiable->is($this->user);

        return (new MailMessage)
            ->subject(__('auth.new_device.subject', ['name' => $this->user->name]))
            ->line(__($self ? 'auth.new_device.intro_self' : 'auth.new_device.intro_other', ['name' => $this->user->name, 'days' => (int) config('edqa.auth.new_device_days')]))
            ->line(__('auth.new_device.time', ['time' => $this->at->copy()->setTimezone('Africa/Lagos')->format('j M Y, H:i').' WAT']))
            ->line(__('auth.new_device.ip', ['ip' => $this->ip]))
            ->line(__('auth.new_device.browser', ['browser' => BrowserName::describe($this->userAgent)]))
            ->line(__($self ? 'auth.new_device.not_you_self' : 'auth.new_device.not_you_other', ['name' => $this->user->name]));
    }
}
