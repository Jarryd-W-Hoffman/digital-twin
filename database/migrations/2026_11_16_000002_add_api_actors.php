<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an API token did is signed with its name, which stays when the
     * token is revoked.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->string('author_api', 100)->nullable()->after('author_id');
            $table->string('closed_by_api', 100)->nullable()->after('closed_by');
        });

        Schema::table('issue_comments', function (Blueprint $table): void {
            $table->string('author_api', 100)->nullable()->after('author_id');
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('acknowledged_by_api', 100)->nullable()->after('acknowledged_by');
        });
    }
};
