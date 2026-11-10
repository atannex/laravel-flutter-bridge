<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmAccessTokenProvider;
use Atannex\LaravelFlutterBridge\Services\FlutterBridgeManager;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class BridgeStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:status';

    /**
     * The console command description.
     */
    protected $description = 'Check the status and health of the Laravel Flutter Bridge environment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📊 Laravel Flutter Bridge Environment Check (v'.FlutterBridgeManager::VERSION.')');
        $this->newLine();

        $rows = [];

        // Check Sanctum
        $hasSanctum = class_exists(Sanctum::class);
        $rows[] = [
            'Component' => 'Laravel Sanctum',
            'Status' => $hasSanctum ? '<info>Installed</info>' : '<comment>Not Installed (Suggested)</comment>',
            'Details' => $hasSanctum ? 'Token authentication active' : 'Install via composer require laravel/sanctum',
        ];

        // Check Devices Table
        $tableName = config('flutter-bridge.devices.table_name', 'flutter_devices');
        $hasTable = Schema::hasTable($tableName);
        $rows[] = [
            'Component' => "Device Table ({$tableName})",
            'Status' => $hasTable ? '<info>Migrated</info>' : '<error>Missing</error>',
            'Details' => $hasTable ? 'Ready for FCM & mobile registration' : 'Run php artisan migrate',
        ];

        // Check Bridge v2 tables
        foreach ([
            'Apps' => (string) config('flutter-bridge.apps.table_name', 'flutter_apps'),
            'Connections' => (string) config('flutter-bridge.connections.table_name', 'flutter_connections'),
            'Commands' => (string) config('flutter-bridge.connections.commands_table_name', 'flutter_connection_commands'),
            'Refresh Tokens' => (string) config('flutter-bridge.refresh_tokens.table_name', 'flutter_refresh_tokens'),
        ] as $label => $table) {
            $exists = Schema::hasTable($table);
            $rows[] = [
                'Component' => "{$label} Table ({$table})",
                'Status' => $exists ? '<info>Migrated</info>' : '<error>Missing</error>',
                'Details' => $exists ? DB::table($table)->count().' row(s)' : 'Run php artisan migrate',
            ];
        }

        // Check Broadcasting Driver
        $broadcastDriver = config('broadcasting.default', 'log');
        $isRealtime = in_array($broadcastDriver, ['reverb', 'pusher', 'ably', 'redis'], true);
        $rows[] = [
            'Component' => 'Broadcasting Driver',
            'Status' => $isRealtime ? "<info>{$broadcastDriver}</info>" : "<comment>{$broadcastDriver}</comment>",
            'Details' => $isRealtime ? 'Compatible with Flutter Echo' : 'Set to reverb or pusher for real-time mobile sync',
        ];

        // Check FCM push credentials (no network calls)
        $rows[] = $this->fcmRow();

        // Check Export Path
        $exportPath = config('flutter-bridge.generator.output_path');
        $pathExists = is_dir(base_path((string) $exportPath));
        $rows[] = [
            'Component' => 'Dart Export Directory',
            'Status' => $pathExists ? '<info>Available</info>' : '<comment>Not Found</comment>',
            'Details' => (string) $exportPath,
        ];

        $this->table(['Component', 'Status', 'Details'], $rows);

        $this->renderBridgeSummary();

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Describe the FCM push configuration.
     *
     * @return array{Component: string, Status: string, Details: string}
     */
    protected function fcmRow(): array
    {
        $fcm = $this->laravel->make(FcmAccessTokenProvider::class)->status();
        $row = ['Component' => 'FCM Push (HTTP v1)'];

        if ($fcm['mode'] === FcmAccessTokenProvider::MODE_NONE) {
            return $row + [
                'Status' => '<comment>Not configured</comment>',
                'Details' => 'Notifications are logged only; set FCM_CREDENTIALS=/path/to/service-account.json',
            ];
        }

        if (! $fcm['valid']) {
            return $row + [
                'Status' => '<error>Invalid</error>',
                'Details' => (string) $fcm['error'],
            ];
        }

        if ($fcm['mode'] === FcmAccessTokenProvider::MODE_STATIC_TOKEN) {
            return $row + [
                'Status' => '<comment>Static token</comment>',
                'Details' => "Project {$fcm['project_id']} via FCM_BEARER_TOKEN (expires hourly; testing only)",
            ];
        }

        return $row + [
            'Status' => '<info>Service account</info>',
            'Details' => "Project {$fcm['project_id']} as {$fcm['client_email']}",
        ];
    }

    /**
     * Render apps and connection statistics when the v2 tables exist.
     */
    protected function renderBridgeSummary(): void
    {
        $appsTable = (string) config('flutter-bridge.apps.table_name', 'flutter_apps');
        $connectionsTable = (string) config('flutter-bridge.connections.table_name', 'flutter_connections');
        $commandsTable = (string) config('flutter-bridge.connections.commands_table_name', 'flutter_connection_commands');

        if (Schema::hasTable($appsTable)) {
            $apps = FlutterApp::query()->orderBy('name')->get();
            $this->newLine();
            $this->info("Client apps ({$apps->count()})");

            if ($apps->isNotEmpty()) {
                $this->table(['Name', 'Key', 'Status', 'Min', 'Latest'], $apps->map(fn (FlutterApp $app) => [
                    $app->name,
                    $app->key,
                    $app->status,
                    $app->min_version ?? '-',
                    $app->latest_version ?? '-',
                ])->all());
            }
        }

        if (Schema::hasTable($connectionsTable) && Schema::hasTable($commandsTable)) {
            $stats = $this->laravel->make(FlutterConnectionManager::class)->stats();
            $this->newLine();
            $this->info('Connection sessions');
            $this->table(
                ['Total', 'Active', 'Online', 'Disconnected', 'Revoked', 'Users online', 'Pending commands'],
                [[
                    $stats['total'],
                    $stats['active'],
                    $stats['online'],
                    $stats['disconnected'],
                    $stats['revoked'],
                    $stats['users_online'],
                    $stats['pending_commands'],
                ]]
            );
        }
    }
}
