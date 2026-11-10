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
        $tableName = config('flutter-bridge.connections.commands_table_name', 'flutter_connection_commands');

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->uuid('connection_id');
                $table->string('type', 64);
                $table->json('payload')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->index(['connection_id', 'delivered_at'], 'flutter_commands_pending_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('flutter-bridge.connections.commands_table_name', 'flutter_connection_commands'));
    }
};
