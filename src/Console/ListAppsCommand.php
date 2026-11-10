<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Illuminate\Console\Command;

class ListAppsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:apps';

    /**
     * The console command description.
     */
    protected $description = 'List registered Flutter client apps';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apps = FlutterApp::query()->withCount('connections')->orderBy('name')->get();

        if ($apps->isEmpty()) {
            $this->info('No Flutter apps registered. Create one with: php artisan flutter:app:create "My App"');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Key', 'Status', 'Platforms', 'Min', 'Latest', 'Sessions', 'Last connected'],
            $apps->map(fn (FlutterApp $app) => [
                $app->id,
                $app->name,
                $app->key,
                $app->isActive() ? "<info>{$app->status}</info>" : "<comment>{$app->status}</comment>",
                $app->platforms ? implode(',', $app->platforms) : 'all',
                $app->min_version ?? '-',
                $app->latest_version ?? '-',
                $app->connections_count,
                $app->last_connected_at?->diffForHumans() ?? 'never',
            ])->all()
        );

        return self::SUCCESS;
    }
}
