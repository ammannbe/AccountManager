<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Group of the identity provider for the admins of the app (AUTH_ADMIN_SOURCE=account-manager, e.g. svc-recipes-admin).
            $table->string('admin_group')->nullable()->unique()->after('access_group');
        });

        Schema::table('service_accounts', function (Blueprint $table) {
            // Admin in the app; only counts while the account is active.
            $table->boolean('is_admin')->default(false)->after('status');
        });

        // Functions of a service the AccountManager unlocks (e.g. shoppinglist.ai).
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Sent to the app in the entitlements (stable, e.g. shoppinglist.ai).
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            // Optional group of the identity provider for users with the feature (e.g. feat-shoppinglist-ai).
            $table->string('group')->nullable()->unique();
            $table->timestamps();

            $table->unique(['service_id', 'key']);
        });

        // Offers: a set of features of one service, or a bundle across services (service_id null).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // For the invoices of a later phase; nothing is billed yet.
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('interval', 10)->nullable();
            // Inactive plans can no longer be assigned; existing subscriptions keep running.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();

            $table->unique(['plan_id', 'feature_id']);
        });

        // A plan assigned to a user by an admin (no payments yet).
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
        });

        // A feature an admin granted a user directly, without a plan.
        Schema::create('feature_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'feature_id']);
        });

        // Manual suspensions of accounts or subscriptions with the reason mailed to the user (history).
        Schema::create('suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at');
            $table->text('lift_message')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
        });

        // Last entitlements snapshot per user and service: its hash and version (for the apps' ordering).
        Schema::create('entitlement_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->string('hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_states');
        Schema::dropIfExists('suspensions');
        Schema::dropIfExists('feature_grants');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('features');

        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['admin_group']);
            $table->dropColumn('admin_group');
        });
    }
};
