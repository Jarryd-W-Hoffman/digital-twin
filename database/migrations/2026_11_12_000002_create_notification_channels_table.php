<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 16);
            // Email addresses or a webhook URL, encrypted: a webhook URL is a secret.
            $table->text('target');
            $table->text('secret')->nullable();
            $table->string('min_severity', 16);
            $table->boolean('notify_cleared')->default(true);
            $table->string('quiet_from', 5)->nullable();
            $table->string('quiet_until', 5)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('alert_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('notification_channel_id')->nullable()->constrained()->nullOnDelete();
            // Kept so the log still names a channel after it's deleted.
            $table->string('channel_name', 100);
            $table->string('event', 16);
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('attempted_at')->nullable();

            $table->index(['alert_id', 'created_at']);
        });
    }
};
