<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Messages;

class FlutterPushMessage
{
    protected string $title = '';

    protected string $body = '';

    protected ?string $image = null;

    protected ?string $sound = 'default';

    protected ?int $badge = null;

    protected ?string $clickAction = null;

    protected ?string $channelId = null;

    protected array $data = [];

    /**
     * Set the notification title.
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the notification body text.
     */
    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Set optional notification image URL.
     */
    public function image(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Set the notification sound.
     */
    public function sound(?string $sound): static
    {
        $this->sound = $sound;

        return $this;
    }

    /**
     * Set the application badge count.
     */
    public function badge(?int $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    /**
     * Set Android notification channel ID.
     */
    public function channelId(?string $channelId): static
    {
        $this->channelId = $channelId;

        return $this;
    }

    /**
     * Set the click action or deep link.
     */
    public function clickAction(?string $clickAction): static
    {
        $this->clickAction = $clickAction;

        return $this;
    }

    /**
     * Set additional arbitrary key-value payload data.
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Transform to FCM v1 message structure (without the target token).
     *
     * sound / channelId map to android.notification and sound / badge to
     * apns.payload.aps. Data values are stringified as FCM requires.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $notification = [
            'title' => $this->title,
            'body' => $this->body,
        ];

        if ($this->image !== null) {
            $notification['image'] = $this->image;
        }

        $data = array_map(fn ($v) => is_string($v) ? $v : (string) json_encode($v), $this->data);

        if ($this->clickAction !== null) {
            $data['click_action'] = $this->clickAction;
        }

        $payload = ['notification' => $notification];

        // FCM rejects an empty list for `data`; it must be omitted or a map.
        if ($data !== []) {
            $payload['data'] = $data;
        }

        $android = array_filter([
            'channel_id' => $this->channelId,
            'sound' => $this->sound,
        ], fn ($value) => $value !== null && $value !== '');

        if ($android !== []) {
            $payload['android'] = ['notification' => $android];
        }

        $aps = array_filter([
            'sound' => $this->sound !== '' ? $this->sound : null,
            'badge' => $this->badge,
        ], fn ($value) => $value !== null);

        if ($aps !== []) {
            $payload['apns'] = ['payload' => ['aps' => $aps]];
        }

        return $payload;
    }
}
