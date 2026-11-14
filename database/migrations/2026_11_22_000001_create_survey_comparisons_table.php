<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Areas of a twin where surveys of the ground are compared, each with
     * the surveys in order and, once measured, the cut and fill between each
     * and the one before.
     */
    public function up(): void
    {
        Schema::create('survey_comparisons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('area');
            $table->json('surveys');
            $table->string('status', 16);
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['twin_id', 'created_at']);
        });
    }
};
