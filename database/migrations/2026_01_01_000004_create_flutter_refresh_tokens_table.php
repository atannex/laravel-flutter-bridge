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
        $tableName = config('flutter-bridge.refresh_tokens.table_name', 'flutter_refresh_tokens');

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('user_type')->nullable();
                $table->unsignedBigInteger('user_id')->index();
                $table->char('token', 64)->unique(); // sha256 hash of the plain token
                $table->uuid('family')->index();
                $table->unsignedBigInteger('access_token_id')->nullable()->index();
                $table->uuid('connection_id')->nullable()->index();
                $table->string('device_id')->nullable();
                $table->string('device_name')->nullable();
                $table->json('abilities')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 32)->nullable();
                $table->unsignedBigInteger('replaced_by_id')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'device_id'], 'flutter_refresh_tokens_user_device_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('flutter-bridge.refresh_tokens.table_name', 'flutter_refresh_tokens'));
    }
};
