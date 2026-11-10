<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ListConnectionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:connections
                            {--user= : Filter by user id}
                            {--status= : active, disconnected, revoked or online}
                            {--app= : Filter by app key (or id)}
                            {--limit=50 : Maximum rows to display}';

    /**
     * The console command description.
     */
    protected $description = 'List Flutter connection sessions with summary statistics';

    /**
     * Execute the console command.
     */
    public function handle(FlutterConnectionManager $connections, FlutterAppManager $apps): int
    {
        $query = FlutterConnection::query()->with('flutterApp');

        if (($user = $this->option('user')) !== null) {
            $query->forUser($user);
        }

        if (($status = $this->option('status')) !== null) {
            if ($status === 'online') {
                $query->online();
            } elseif (in_array($status, ['active', 'disconnected', 'revoked'], true)) {
                $query->where('status', $status);
            } else {
                $this->error('Status must be one of: active, disconnected, revoked, online.');

                return self::FAILURE;
            }
        }

        if (($appKey = $this->option('app')) !== null) {
            try {
                $query->where('app_id', $apps->resolve($appKey)->getKey());
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $rows = $query->orderByDesc('last_seen_at')->limit(max(1, (int) $this->option('limit')))->get();

        if ($rows->isEmpty()) {
            $this->info('No connection sessions found.');
        } else {
            $this->table(
                ['ID', 'User', 'App', 'Device', 'Platform', 'Version', 'Status', 'Online', 'Last seen'],
                $rows->map(fn (FlutterConnection $c) => [
                    $c->id,
                    $c->user_id ?? '-',
                    $c->flutterApp->name ?? '-',
                    $c->device_name ? "{$c->device_name} ({$c->device_id})" : $c->device_id,
                    $c->platform,
                    $c->app_version ?? '-',
                    $c->status,
                    $c->isOnline() ? '<info>yes</info>' : 'no',
                    $c->last_seen_at?->diffForHumans() ?? 'never',
                ])->all()
            );
        }

        $stats = $connections->stats();
        $this->newLine();
        $this->line(sprintf(
            'Total: <comment>%d</comment> · Active: <comment>%d</comment> · Online: <info>%d</info> · Disconnected: %d · Revoked: %d · Users online: %d · Pending commands: %d',
            $stats['total'],
            $stats['active'],
            $stats['online'],
            $stats['disconnected'],
            $stats['revoked'],
            $stats['users_online'],
            $stats['pending_commands']
        ));

        return self::SUCCESS;
    }
}
