<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            // Null: told when an alert is raised. Otherwise only when it's still
            // unacknowledged this many minutes later.
            $table->unsignedSmallInteger('escalate_after_minutes')->nullable()->after('notify_cleared');
        });

        Schema::table('alert_notifications', function (Blueprint $table): void {
            $table->index(['notification_channel_id', 'event']);
        });
    }
};
