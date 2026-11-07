<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Billing address for the invoices (structured as the Swiss QR-bill needs it).
        Schema::table('users', function (Blueprint $table) {
            // Name or company on the invoice; empty: the user's name.
            $table->string('billing_name', 70)->nullable()->after('email');
            // Street and house number.
            $table->string('billing_street', 70)->nullable()->after('billing_name');
            $table->string('billing_postal_code', 16)->nullable()->after('billing_street');
            $table->string('billing_city', 35)->nullable()->after('billing_postal_code');
            // ISO 3166-1 alpha-2.
            $table->string('billing_country', 2)->nullable()->after('billing_city');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Number of billing periods (from starts_at) already on an invoice.
            $table->unsignedInteger('billed_periods')->default(0)->after('ends_at');
        });

        // Invoices are kept for 10 years (OR), also after the user is deleted: never deleted, only
        // cancelled; recipient and creditor are snapshots.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            // e.g. 2026-0001 (prefix from the config), sequential per year.
            $table->string('number', 40)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Name, address and email at the time of the invoice.
            $table->json('recipient');
            // Name, address and IBAN of the creditor at the time of the invoice.
            $table->json('creditor');
            $table->string('currency', 3);
            $table->unsignedInteger('total_cents');
            $table->string('status', 20)->default('draft');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issued_at');
            $table->date('due_at');
            // QRR (QR-IBAN), SCOR (creditor reference) or NON; the reference matches payments later (camt.054).
            $table->string('reference_type', 4);
            $table->string('reference', 27)->nullable()->unique();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->text('payment_note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_cents');
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billed_periods');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['billing_name', 'billing_street', 'billing_postal_code', 'billing_city', 'billing_country']);
        });
    }
};
