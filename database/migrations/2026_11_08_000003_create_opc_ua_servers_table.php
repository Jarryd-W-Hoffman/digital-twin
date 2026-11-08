<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opc_ua_servers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('endpoint', 255);
            $table->string('security_mode', 16);
            $table->string('security_policy', 32);
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::table('twins', function (Blueprint $table): void {
            $table->json('gateway_opcua')->nullable();
        });
    }
};
