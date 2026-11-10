<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CreateAppCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:app:create
                            {name : Display name of the app}
                            {--platforms= : Comma separated platforms (default: all)}
                            {--min-version= : Minimum supported client version}
                            {--latest-version= : Latest released client version}
                            {--update-url= : Store / download URL shown to outdated clients}';

    /**
     * The console command description.
     */
    protected $description = 'Register a Flutter client app and print its app key';

    /**
     * Execute the console command.
     */
    public function handle(FlutterAppManager $apps): int
    {
        try {
            $app = $apps->create((string) $this->argument('name'), array_filter([
                'platforms' => $this->option('platforms'),
                'min_version' => $this->option('min-version'),
                'latest_version' => $this->option('latest-version'),
                'update_url' => $this->option('update-url'),
            ], fn ($value) => $value !== null));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Flutter app \"{$app->name}\" created (id {$app->id}).");
        $this->newLine();
        $this->line("  App key: <comment>{$app->key}</comment>");
        $this->newLine();
        $this->line('Configure the Flutter client with this key (X-Flutter-App-Key), e.g.:');
        $this->line("  php artisan flutter:export-config --app-key={$app->key}");

        return self::SUCCESS;
    }
}
