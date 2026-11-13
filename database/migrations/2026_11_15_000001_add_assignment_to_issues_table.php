<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->foreignUuid('assignee_id')->nullable()->after('author_id')->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable()->after('assignee_id');
            // The alert it was raised from, if any.
            $table->foreignUuid('alert_id')->nullable()->after('due_on')->constrained()->nullOnDelete();

            $table->index(['assignee_id', 'closed_at']);
        });

        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->boolean('notify_issues')->default(false)->after('notify_cleared');
        });
    }
};
