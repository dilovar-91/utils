<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('last_expiry_notified_at')->nullable()->after('last_checked_at');
            $table->integer('last_expiry_notified_days')->nullable()->after('last_expiry_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'last_expiry_notified_at',
                'last_expiry_notified_days',
            ]);
        });
    }
};
