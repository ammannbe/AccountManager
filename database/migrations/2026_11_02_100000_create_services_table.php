<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            // oidc: logs in through the identity provider; external: accounts are managed elsewhere (e.g. Wizarr).
            $table->string('type')->default('oidc');
            // Group of the identity provider that grants access (e.g. svc-recipes).
            $table->string('access_group')->nullable()->unique();
            $table->boolean('self_signup')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('external_username')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'service_id']);
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('locale', 5)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('service_accounts');
        Schema::dropIfExists('services');
    }
};
