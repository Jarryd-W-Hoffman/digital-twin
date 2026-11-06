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
            $table->string('gateway_token_hash', 64)->nullable()->unique();
            $table->string('gateway_url')->nullable();
            $table->timestamp('gateway_seen_at')->nullable();
        });
    }
};
