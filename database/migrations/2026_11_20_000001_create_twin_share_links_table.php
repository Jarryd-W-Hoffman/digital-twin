<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links that show one twin, read only, to people who aren't members: kept
     * only as a hash, until they expire or are revoked.
     */
    public function up(): void
    {
        Schema::create('twin_share_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('twin_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 4);
            $table->boolean('live')->default(true);
            $table->timestamp('expires_at');
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }
};
