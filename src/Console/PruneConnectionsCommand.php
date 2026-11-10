<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Illuminate\Console\Command;

class PruneConnectionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:connections:prune
                            {--days= : Delete records older than this many days (default: connections.prune_after_days)}';

    /**
     * The console command description.
     */
    protected $description = 'Delete ended Flutter sessions, old commands and dead refresh tokens';

    /**
     * Execute the console command.
     */
    public function handle(FlutterConnectionManager $connections): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $result = $connections->prune($days);

        $this->info(sprintf(
            'Pruned %d connection(s), %d command(s) and %d refresh token(s).',
            $result['connections'],
            $result['commands'],
            $result['refresh_tokens']
        ));

        return self::SUCCESS;
    }
}
