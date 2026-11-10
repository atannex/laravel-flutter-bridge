<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DeleteAppCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:app:delete
                            {key : App key (or id)}
                            {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Delete a Flutter client app and revoke its sessions';

    /**
     * Execute the console command.
     */
    public function handle(FlutterAppManager $apps): int
    {
        try {
            $app = $apps->resolve((string) $this->argument('key'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete app \"{$app->name}\" and revoke all of its sessions?", false)) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        $apps->delete($app);
        $this->info("Flutter app \"{$app->name}\" deleted.");

        return self::SUCCESS;
    }
}
