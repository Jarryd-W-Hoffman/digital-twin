<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twins', function (Blueprint $table): void {
            $table->boolean('opcua_writes')->default(false);
        });

        Schema::create('opc_ua_writes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('server', 100);
            $table->string('node', 255);
            $table->string('asset', 64);
            $table->string('metric', 64);
            $table->double('requested');
            $table->double('setpoint');
            $table->double('value');
            $table->boolean('succeeded');
            $table->string('error', 500)->nullable();
            $table->timestamp('written_at');

            $table->index(['twin_id', 'written_at']);
        });
    }
};
