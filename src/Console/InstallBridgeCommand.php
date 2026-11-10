<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Illuminate\Console\Command;

class InstallBridgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:install 
                            {--force : Overwrite existing published files}';

    /**
     * The console command description.
     */
    protected $description = 'Install and initialize Laravel Flutter Bridge';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Installing Laravel Flutter Bridge...');

        // Publish configuration
        $this->call('vendor:publish', [
            '--tag' => 'flutter-bridge-config',
            '--force' => $this->option('force'),
        ]);

        // Publish migrations
        $this->call('vendor:publish', [
            '--tag' => 'flutter-bridge-migrations',
            '--force' => $this->option('force'),
        ]);

        $this->newLine();
        $this->info('✅ Published configuration and migrations successfully.');
        $this->comment('Next steps:');
        $this->line(' 1. Run "php artisan migrate" to create the flutter_devices, flutter_apps, flutter_connections,');
        $this->line('    flutter_connection_commands and flutter_refresh_tokens tables.');
        $this->line(' 2. Add "use HasApiTokens, HasFlutterTokens, HasFlutterDevices;" to your App\\Models\\User model.');
        $this->line(' 3. Register your Flutter app: php artisan flutter:app:create "My App" --platforms=android,ios');
        $this->line(' 4. Generate the client config: php artisan flutter:export-config --app-key=<key>');
        $this->line(' 5. Schedule cleanup: Schedule::command(\'flutter:connections:prune\')->daily();');
        $this->line(' 6. Run "php artisan flutter:status" to verify installation.');

        return self::SUCCESS;
    }
}
