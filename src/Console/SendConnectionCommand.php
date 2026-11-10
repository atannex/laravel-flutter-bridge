<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Illuminate\Console\Command;

class SendConnectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:connections:send
                            {type : Command type (revoked, logout, refresh_config, force_update, message, ...)}
                            {id? : Connection session id}
                            {--user= : Send to every session of this user id}
                            {--payload= : JSON object payload}
                            {--all : Send to every active session}
                            {--ttl= : Minutes the command stays deliverable}';

    /**
     * The console command description.
     */
    protected $description = 'Queue a server -> client command for Flutter connection sessions';

    /**
     * Execute the console command.
     */
    public function handle(FlutterConnectionManager $connections): int
    {
        $payload = [];
        $raw = $this->option('payload');

        if ($raw !== null && $raw !== '') {
            $payload = json_decode((string) $raw, true);

            if (! is_array($payload) || (array_is_list($payload) && $payload !== [])) {
                $this->error('--payload must be a JSON object.');

                return self::FAILURE;
            }
        }

        $type = (string) $this->argument('type');
        $ttl = $this->option('ttl') !== null ? (int) $this->option('ttl') : null;
        $id = $this->argument('id');
        $user = $this->option('user');

        if ($id !== null) {
            try {
                $command = $connections->sendCommand((string) $id, $type, $payload, $ttl);
            } catch (FlutterBridgeException) {
                $this->error("Connection [{$id}] not found.");

                return self::FAILURE;
            }

            $this->info("Queued \"{$type}\" command #{$command->id} for connection {$id}.");

            return self::SUCCESS;
        }

        if ($user !== null) {
            $count = $connections->sendCommandToUser((string) $user, $type, $payload, $ttl)->count();
            $this->info("Queued \"{$type}\" for {$count} connection(s) of user {$user}.");

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $count = $connections->sendCommandToAll($type, $payload, $ttl)->count();
            $this->info("Queued \"{$type}\" for {$count} active connection(s).");

            return self::SUCCESS;
        }

        $this->error('Pass a connection id, --user=<id> or --all.');

        return self::FAILURE;
    }
}
