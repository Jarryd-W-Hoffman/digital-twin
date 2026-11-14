<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether members other than owners must have signed in through the
     * identity provider to work in the organisation.
     */
    public function up(): void
    {
        Schema::table('sso_connections', function (Blueprint $table): void {
            $table->boolean('required')->default(false)->after('client_secret');
        });
    }
};
