<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sensor readings, as gateways report them. No id column: TimescaleDB
     * needs every unique index on a hypertable to include its time column.
     */
    public function up(): void
    {
        Schema::create('readings', function (Blueprint $table): void {
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 64);
            $table->string('metric', 64);
            $table->double('value');
            $table->timestampTz('recorded_at', 3);
            $table->index(['twin_id', 'asset', 'metric', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};
