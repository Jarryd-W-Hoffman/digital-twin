<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PLCs, meters and drives the project's gateways read over Modbus TCP,
     * and what each twin's gateway last said of its connections to them.
     */
    public function up(): void
    {
        Schema::create('modbus_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('host');
            $table->unsignedInteger('port');
            $table->unsignedTinyInteger('unit_id');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::table('twins', function (Blueprint $table): void {
            $table->json('gateway_modbus')->nullable();
        });
    }
};
