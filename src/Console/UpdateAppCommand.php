<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class UpdateAppCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:app:update
                            {key : App key (or id)}
                            {--status= : active or suspended}
                            {--min-version= : Minimum supported client version ("" to clear)}
                            {--latest-version= : Latest released client version ("" to clear)}
                            {--update-url= : Store / download URL ("" to clear)}
                            {--name= : New display name}';

    /**
     * The console command description.
     */
    protected $description = 'Update a Flutter client app (status, version policy, name)';

    /**
     * Execute the console command.
     */
    public function handle(FlutterAppManager $apps): int
    {
        $attributes = [];

        foreach ([
            'status' => 'status',
            'min-version' => 'min_version',
            'latest-version' => 'latest_version',
            'update-url' => 'update_url',
            'name' => 'name',
        ] as $option => $attribute) {
            $value = $this->option($option);

            if ($value !== null) {
                $attributes[$attribute] = $value;
            }
        }

        if ($attributes === []) {
            $this->warn('Nothing to update. Pass at least one option.');

            return self::FAILURE;
        }

        try {
            $app = $apps->update((string) $this->argument('key'), $attributes);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Flutter app \"{$app->name}\" updated.");
        $this->table(['Status', 'Min', 'Latest', 'Update URL'], [[
            $app->status,
            $app->min_version ?? '-',
            $app->latest_version ?? '-',
            $app->update_url ?? '-',
        ]]);

        return self::SUCCESS;
    }
}
