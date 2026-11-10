<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Illuminate\Console\Command;

class RevokeConnectionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:connections:revoke
                            {id? : Connection session id}
                            {--user= : Revoke every session of this user id}
                            {--reason= : Reason sent to the client}';

    /**
     * The console command description.
     */
    protected $description = 'Revoke a Flutter connection session, or all sessions of a user';

    /**
     * Execute the console command.
     */
    public function handle(FlutterConnectionManager $connections): int
    {
        $id = $this->argument('id');
        $user = $this->option('user');
        $reason = $this->option('reason') ?: 'revoked_by_admin';

        if ($id === null && $user === null) {
            $this->error('Pass a connection id or --user=<id>.');

            return self::FAILURE;
        }

        if ($id !== null) {
            try {
                $connection = $connections->revoke((string) $id, $reason);
            } catch (FlutterBridgeException) {
                $this->error("Connection [{$id}] not found.");

                return self::FAILURE;
            }

            $this->info("Connection {$connection->id} revoked.");

            return self::SUCCESS;
        }

        $count = $connections->revokeAllForUser((string) $user, null, $reason);
        $this->info("Revoked {$count} connection(s) of user {$user}.");

        return self::SUCCESS;
    }
}
