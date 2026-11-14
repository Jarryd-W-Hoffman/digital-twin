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
            $table->string('report', 16)->nullable()->after('notify_issues');
            // When the report that fell due was sent, so each is sent once.
            $table->timestamp('last_report_at')->nullable()->after('report');
            $table->index('report');
        });
    }
};
