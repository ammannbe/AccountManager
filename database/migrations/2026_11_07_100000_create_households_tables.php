<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Receives the signed webhooks (households, later entitlements).
            $table->string('webhook_url')->nullable()->after('login_url');
            // Encrypted: signs the webhooks and authenticates the app's API calls (HTTP Basic, key:secret).
            $table->text('secret')->nullable()->after('webhook_url');
            $table->timestamp('secret_created_at')->nullable()->after('secret');
        });

        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Incremented on every change; the apps ignore events older than the version they know.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamp('joined_at')->nullable();

            $table->unique(['household_id', 'user_id']);
        });

        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            // Only the hash is stored; the plaintext token lives in the invitation mail.
            $table->string('token_hash', 64)->unique();
            $table->string('role', 20)->default('member');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'email']);
        });

        // What the apps share with a household (reported by the apps, shown in the overview).
        Schema::create('household_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 50);
            $table->string('resource_id', 100);
            $table->string('label');
            $table->timestamps();

            $table->unique(['household_id', 'service_id', 'resource_type', 'resource_id'], 'household_shares_resource_unique');
        });

        // Local households an app proposes when it switches to HOUSEHOLDS_DRIVER=account-manager; an admin confirms them.
        Schema::create('household_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('local_id', 100);
            $table->string('name');
            // [{sub, role}] as reported by the app.
            $table->json('members');
            $table->string('status', 20)->default('pending');
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'local_id']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->index();
            $table->string('event', 100);
            // The exact signed body, so every attempt sends the same bytes.
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('household_imports');
        Schema::dropIfExists('household_shares');
        Schema::dropIfExists('household_invitations');
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'secret', 'secret_created_at']);
        });
    }
};
