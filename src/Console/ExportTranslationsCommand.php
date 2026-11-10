<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExportTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flutter:export-translations
                            {--locale=en : The language locale to export}
                            {--output= : Output destination JSON path}';

    /**
     * The console command description.
     */
    protected $description = 'Export Laravel language strings to Flutter localization JSON';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $locale = (string) $this->option('locale');
        $translations = [];

        // 1. Check root lang/{locale}.json
        $jsonPath = base_path("lang/{$locale}.json");
        if (File::exists($jsonPath)) {
            $jsonContent = json_decode(File::get($jsonPath), true);
            if (is_array($jsonContent)) {
                $translations = array_merge($translations, $jsonContent);
            }
        }

        // 2. Check lang/{locale}/*.php or resources/lang/{locale}/*.php
        $directories = [
            base_path("lang/{$locale}"),
            resource_path("lang/{$locale}"),
        ];

        foreach ($directories as $dir) {
            if (File::isDirectory($dir)) {
                foreach (File::files($dir) as $file) {
                    if ($file->getExtension() === 'php') {
                        $group = $file->getFilenameWithoutExtension();
                        $items = include $file->getRealPath();
                        if (is_array($items)) {
                            foreach ($items as $k => $v) {
                                if (is_string($v)) {
                                    $translations["{$group}.{$k}"] = $v;
                                }
                            }
                        }
                    }
                }
            }
        }

        $outputPath = $this->option('output')
            ?? config('flutter-bridge.generator.output_path', '../flutter-laravel-bridge/lib/src/generated') . "/intl_{$locale}.json";

        if (!Str::startsWith($outputPath, '/')) {
            $outputPath = base_path($outputPath);
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        File::put($outputPath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("✅ Exported " . count($translations) . " translation strings for [{$locale}] to: {$outputPath}");
        return self::SUCCESS;
    }
}
