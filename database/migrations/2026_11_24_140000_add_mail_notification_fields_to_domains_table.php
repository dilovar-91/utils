<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('last_health_mailed_status')->nullable()->after('last_health_notified_status');
            $table->timestamp('last_expiry_mailed_at')->nullable()->after('last_expiry_notified_days');
            $table->integer('last_expiry_mailed_days')->nullable()->after('last_expiry_mailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'last_health_mailed_status',
                'last_expiry_mailed_at',
                'last_expiry_mailed_days',
            ]);
        });
    }
};
