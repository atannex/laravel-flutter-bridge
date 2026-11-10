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
        $tableName = config('flutter-bridge.connections.table_name', 'flutter_connections');

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('app_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('device_id')->index();
                $table->string('platform', 32)->default('unknown');
                $table->string('device_name')->nullable();
                $table->string('app_version', 64)->nullable();
                $table->string('os_version', 64)->nullable();
                $table->string('bridge_version', 32)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('status', 16)->default('active')->index(); // active, disconnected, revoked
                $table->string('state', 16)->nullable(); // foreground, background
                $table->json('metadata')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason')->nullable();
                $table->timestamps();

                $table->index(['app_id', 'device_id'], 'flutter_connections_app_device_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('flutter-bridge.connections.table_name', 'flutter_connections'));
    }
};
