<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registrations without a service create only the account; the services follow in the portal.
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });

        Schema::table('services', function (Blueprint $table) {
            // Starts the login of the service through the identity provider (e.g. https://recipes.example.com/auth/oidc/redirect).
            $table->string('login_url')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('login_url');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
