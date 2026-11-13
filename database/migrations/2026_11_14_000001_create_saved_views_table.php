<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->json('state');
            $table->boolean('shared')->default(false);
            $table->timestamps();

            $table->index(['twin_id', 'shared']);
        });

        Schema::table('twins', function (Blueprint $table): void {
            $table->foreignUuid('opening_view_id')->nullable()->constrained('saved_views')->nullOnDelete();
        });
    }
};
