<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organisation's OpenID Connect identity provider, the email domains
     * that sign in through it, and the people linked to it by the subject
     * the provider knows them by.
     */
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('client_id');
            $table->text('client_secret');
            $table->boolean('auto_join')->default(false);
            $table->string('default_role', 16)->default('viewer');
            $table->timestamps();
        });

        Schema::create('sso_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('sso_connection_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
        });

        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('sso_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('email');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();

            $table->unique(['sso_connection_id', 'subject']);
            $table->unique(['sso_connection_id', 'user_id']);
        });
    }
};
