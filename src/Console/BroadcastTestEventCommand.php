<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class GenericFlutterBroadcastEvent implements ShouldBroadcastNow
{
    public function __construct(
        public string $channelName,
        public string $eventName,
        public array $payload = []
    ) {}

    public function broadcastOn(): array
    {
        return [$this->channelName];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return array_merge($this->payload, [
            '_dispatched_from' => 'artisan flutter:broadcast',
            '_timestamp' => now()->toIso8601String(),
        ]);
    }
}

class BroadcastTestEventCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:broadcast 
                            {event : The event name to broadcast (e.g. OrderShipped)}
                            {channel : The channel name (e.g. orders or private-users.1)}
                            {--data= : JSON string of payload data}';

    /**
     * The console command description.
     */
    protected $description = 'Broadcast a real-time event to connected Flutter clients';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventName = (string) $this->argument('event');
        $channelName = (string) $this->argument('channel');
        $rawData = $this->option('data');

        $payload = [];
        if ($rawData) {
            $parsed = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $payload = $parsed;
            } else {
                $payload = ['message' => $rawData];
            }
        } else {
            $payload = [
                'message' => 'Real-time test ping from Laravel to Flutter',
                'rand' => rand(1000, 9999),
            ];
        }

        $this->info("📡 Broadcasting event '{$eventName}' on channel '{$channelName}'...");

        event(new GenericFlutterBroadcastEvent($channelName, $eventName, $payload));

        $this->info("✅ Broadcast dispatched successfully! Check your Flutter app.");
        return self::SUCCESS;
    }
}
