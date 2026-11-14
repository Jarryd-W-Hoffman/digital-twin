<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metrics watched for readings unusual for their time of day, or stuck,
     * with the band learned from their history; and the periods a twin's
     * history is to be left out of learning and checking (a shutdown, say).
     */
    public function up(): void
    {
        Schema::create('anomaly_monitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 64);
            $table->string('metric', 64);
            $table->string('sensitivity', 8)->default('medium');
            $table->string('severity', 16)->default('warning');
            $table->boolean('bands')->default(true);
            $table->unsignedSmallInteger('flatline_hours')->nullable();
            $table->json('baseline')->nullable();
            $table->timestamp('learned_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['twin_id', 'asset', 'metric']);
        });

        Schema::create('anomaly_exclusions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['twin_id', 'ends_at']);
        });
    }
};
