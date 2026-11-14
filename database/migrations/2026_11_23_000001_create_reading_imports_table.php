<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files of readings imported into a twin's history, with how their
     * columns map onto its metrics and, once imported, what came of it.
     */
    public function up(): void
    {
        Schema::create('reading_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('path')->nullable();
            $table->json('settings');
            $table->json('series');
            $table->string('status', 16);
            $table->json('summary')->nullable();
            $table->timestampTz('first_at', 3)->nullable();
            $table->timestampTz('last_at', 3)->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['twin_id', 'created_at']);
        });
    }
};
