<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->double('lon');
            $table->double('lat');
            $table->double('height');
            $table->json('view');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['twin_id', 'number']);
            $table->index(['twin_id', 'closed_at']);
        });

        Schema::create('issue_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();

            $table->index(['issue_id', 'created_at']);
        });
    }
};
