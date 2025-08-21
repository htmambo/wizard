<?php

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
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('refresh_token', 80)->nullable()->unique()->after('token');
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
            $table->index(['refresh_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex(['refresh_token']);
            $table->dropColumn(['refresh_token', 'refresh_expires_at']);
        });
    }
};