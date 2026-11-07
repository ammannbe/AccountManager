<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // oidc-group (default: only the group sync), wizarr, manual (App\Connectors\ConnectorRegistry).
            $table->string('connector')->default('oidc-group')->after('type');
            // Encrypted JSON: base URL, API key and options of the connector.
            $table->text('connector_config')->nullable()->after('connector');
        });

        Schema::table('service_accounts', function (Blueprint $table) {
            // Id of the account in the external service (e.g. the Wizarr user id).
            $table->string('external_id')->nullable()->after('is_admin');
            // Encrypted JSON of the connector (e.g. the open Wizarr invitation and its state).
            $table->text('connector_data')->nullable()->after('external_username');
        });

        Schema::create('connector_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Kept when the account or the user is deleted (deprovisioning runs after that).
            $table->foreignId('service_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('connector');
            $table->string('action');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('message')->nullable();
            // Encrypted snapshot of the account (user, external id, connector data) for deprovisioning.
            $table->text('snapshot')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['service_account_id', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_runs');

        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'connector_data']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['connector', 'connector_config']);
        });
    }
};
