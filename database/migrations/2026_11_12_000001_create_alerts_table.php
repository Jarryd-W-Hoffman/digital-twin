<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 64);
            $table->string('asset', 64);
            $table->string('severity', 16);
            $table->string('message', 500);
            $table->double('value');
            $table->timestamp('raised_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['twin_id', 'raised_at']);
            $table->index(['twin_id', 'cleared_at']);
        });
    }
};
