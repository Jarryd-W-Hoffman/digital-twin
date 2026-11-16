<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The optional features an organisation's owners and admins have turned
     * on, such as processing drone photos.
     */
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->json('features')->nullable();
        });
    }
};
