<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Fortify;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for the emailed codes: a markdown mail with the app name, the code
 * and its lifetime. Every string goes through __() so apps can translate it
 * (lang/<locale>.json). Not queued, so the code arrives while the user waits.
 */
abstract class VerificationCodeNotification extends Notification
{
    public function __construct(
        public string $code,
        public int $expiresIn
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $replace = [
            'app' => (string) config('app.name', 'Laravel'),
            'code' => $this->code,
            'minutes' => (string) max(1, (int) ceil($this->expiresIn / 60)),
        ];

        return (new MailMessage)
            ->subject(__($this->subjectLine(), $replace))
            ->greeting(__('Hello!'))
            ->line(__($this->introLine(), $replace))
            ->line('# '.$this->code)
            ->line(__('This code expires in :minutes minutes.', $replace))
            ->line(__($this->outroLine(), $replace))
            ->salutation(__('Regards,')."\n".$replace['app']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return ['expires_in' => $this->expiresIn];
    }

    abstract protected function subjectLine(): string;

    abstract protected function introLine(): string;

    abstract protected function outroLine(): string;
}
