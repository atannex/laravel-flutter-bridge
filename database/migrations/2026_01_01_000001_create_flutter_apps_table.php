<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('flutter-bridge.apps.table_name', 'flutter_apps');

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('key', 64)->unique();
                $table->string('status', 16)->default('active')->index(); // active, suspended
                $table->json('platforms')->nullable(); // null = all platforms
                $table->string('min_version', 64)->nullable();
                $table->string('latest_version', 64)->nullable();
                $table->string('update_url', 2048)->nullable();
                $table->json('settings')->nullable();
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('flutter-bridge.apps.table_name', 'flutter_apps'));
    }
};
