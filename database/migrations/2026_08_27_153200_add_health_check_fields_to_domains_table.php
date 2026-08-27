<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('health_check_enabled')->default(false)->after('last_error');
            $table->string('health_url')->nullable()->after('health_check_enabled');
            $table->string('health_status')->default('unknown')->after('health_url');
            $table->unsignedSmallInteger('health_status_code')->nullable()->after('health_status');
            $table->unsignedInteger('health_response_time_ms')->nullable()->after('health_status_code');
            $table->timestamp('last_health_checked_at')->nullable()->after('health_response_time_ms');
            $table->text('last_health_error')->nullable()->after('last_health_checked_at');
            $table->timestamp('last_health_notified_at')->nullable()->after('last_health_error');
            $table->string('last_health_notified_status')->nullable()->after('last_health_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'health_check_enabled',
                'health_url',
                'health_status',
                'health_status_code',
                'health_response_time_ms',
                'last_health_checked_at',
                'last_health_error',
                'last_health_notified_at',
                'last_health_notified_status',
            ]);
        });
    }
};
