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
        $tableName = config('flutter-bridge.devices.table_name', 'flutter_devices');

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('device_id')->index();
                $table->text('fcm_token')->nullable();
                $table->string('platform', 32)->default('unknown'); // android, ios, web, macos, windows, linux
                $table->string('device_name')->nullable();
                $table->string('os_version', 64)->nullable();
                $table->string('app_version', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'device_id'], 'user_device_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('flutter-bridge.devices.table_name', 'flutter_devices');
        Schema::dropIfExists($tableName);
    }
};
