<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('project_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('original_name');
            $table->string('directory');
            $table->string('entry');
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('files');
            $table->timestamps();
        });

        Schema::table('twins', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};
