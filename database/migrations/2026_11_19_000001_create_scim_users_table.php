<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The token an identity provider provisions people with (SCIM 2.0), kept
     * only as a hash, and the people it has provisioned: the userName it knows
     * them by, whether it has them active, and whether it made their account.
     */
    public function up(): void
    {
        Schema::table('sso_connections', function (Blueprint $table): void {
            $table->string('scim_token_hash', 64)->nullable()->unique()->after('default_role');
            $table->string('scim_token_hint', 4)->nullable()->after('scim_token_hash');
            $table->timestamp('scim_token_used_at')->nullable()->after('scim_token_hint');
        });

        Schema::create('scim_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sso_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('user_name');
            $table->string('external_id')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('created_account')->default(false);
            $table->timestamps();

            $table->unique(['sso_connection_id', 'user_name']);
            $table->unique(['sso_connection_id', 'user_id']);
        });
    }
};
