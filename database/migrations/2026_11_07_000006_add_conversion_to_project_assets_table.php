<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_assets', function (Blueprint $table): void {
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->text('log')->nullable();
            $table->string('crs')->nullable();
        });
    }
};
