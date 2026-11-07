<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name', 200);
            $table->json('definition');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'slug']);
        });
    }
};
