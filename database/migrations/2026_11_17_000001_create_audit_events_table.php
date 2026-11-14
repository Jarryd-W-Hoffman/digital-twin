<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what in an organisation. Names are kept as they were, so an
     * event still reads right after its project, twin or person has gone;
     * the project and twin ids are kept without a foreign key for the same
     * reason.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_api', 100)->nullable();
            $table->string('action', 64);
            $table->string('subject')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->uuid('twin_id')->nullable();
            $table->string('twin_name')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['organisation_id', 'created_at']);
            $table->index('created_at');
        });
    }
};
