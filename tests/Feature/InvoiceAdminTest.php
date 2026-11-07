<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanInterval;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    private function configure(): void
    {
        config([
            'billing.creditor.name' => 'Narrenhaus GmbH',
            'billing.creditor.street' => 'Bahnhofstrasse 1',
            'billing.creditor.postal_code' => '8001',
            'billing.creditor.city' => 'Zürich',
            'billing.creditor.country' => 'CH',
            'billing.creditor.iban' => 'CH9300762011623852957',
        ]);
    }

    public function test_only_admins_see_the_invoices(): void
    {
        $invoice = Invoice::factory()->create();

        $this->get('/admin/invoices')->assertOk();
        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee($invoice->number);

        $this->actingAs(User::factory()->create())->get('/admin/invoices')->assertForbidden();
    }

    public function test_the_list_shows_whether_billing_is_off(): void
    {
        Livewire::test(ListInvoices::class)->assertSee('Billing is off until BILLING_CREDITOR_IBAN is set');

        $this->configure();
        Livewire::test(ListInvoices::class)->assertDontSee('Billing is off');
    }

    public function test_admins_run_the_billing_by_hand(): void
    {
        $this->configure();
        $anna = User::factory()->create();
        $plan = Plan::factory()->for(Service::factory())->create(['price_cents' => 1200, 'interval' => PlanInterval::Month]);
        Subscription::factory()->for($anna)->for($plan)->create();

        Livewire::test(ListInvoices::class)
            ->callAction('run')
            ->assertNotified('Billing done');

        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        Notification::assertSentTo($anna, InvoiceIssued::class);

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    }

    public function test_a_broken_configuration_is_reported(): void
    {
        $this->configure();
        config(['billing.reference_type' => 'QRR']);

        Livewire::test(ListInvoices::class)
            ->assertSee('Billing is not configured correctly')
            ->callAction('run')
            ->assertNotified('Billing failed');
    }

    public function test_admins_mark_invoices_as_paid(): void
    {
        $invoice = Invoice::factory()->overdue()->create();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('markPaid', ['paid_at' => now()->addDay()->toDateString()])
            ->assertHasFormErrors(['paid_at']);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('markPaid', ['paid_at' => now()->subDays(2)->toDateString(), 'note' => 'PostFinance'])
            ->assertHasNoFormErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(now()->subDays(2)->toDateString(), $invoice->paid_at?->toDateString());
        $this->assertSame('PostFinance', $invoice->payment_note);
        $this->assertSame($this->admin->id, $invoice->paid_by);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('markPaid')
            ->assertActionHidden('cancel')
            ->assertActionVisible('resend');
    }

    public function test_admins_cancel_invoices_with_a_reason(): void
    {
        $invoice = Invoice::factory()->create();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('cancel', ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('cancel', ['reason' => 'Doppelt verrechnet'])
            ->assertHasNoFormErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame('Doppelt verrechnet', $invoice->cancel_reason);
        $this->assertSame($this->admin->id, $invoice->cancelled_by);
        $this->assertModelExists($invoice);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('markPaid')
            ->assertActionHidden('resend');
    }

    public function test_admins_send_an_invoice_again_and_download_it(): void
    {
        $invoice = Invoice::factory()->create();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('resend')
            ->assertNotified();

        Notification::assertSentTo($invoice->user, InvoiceIssued::class);
        $invoice->refresh();
        $this->assertNotNull($invoice->sent_at);
        Storage::disk('local')->assertExists((string) $invoice->pdf_path);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('pdf')
            ->assertFileDownloaded($invoice->fileName());
    }

    public function test_overdue_invoices_lead_to_the_suspend_actions_of_the_user(): void
    {
        $invoice = Invoice::factory()->overdue()->create();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('user')
            ->assertActionHasLabel('user', 'Suspend plan or access')
            ->assertActionHasUrl('user', UserResource::getUrl('edit', ['record' => $invoice->user_id]));

        $this->assertSame('1', InvoiceResource::getNavigationBadge());
    }

    public function test_the_user_page_lists_the_invoices_and_edits_the_billing_address(): void
    {
        $anna = User::factory()->create(['name' => 'Anna Muster', 'email' => 'anna@example.com']);
        $invoice = Invoice::factory()->for($anna)->create();

        Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $anna, 'pageClass' => EditUser::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$invoice]);

        Livewire::test(EditUser::class, ['record' => $anna->getRouteKey()])
            ->fillForm(['billing_postal_code' => '3000'])
            ->call('save')
            ->assertHasFormErrors(['billing_city', 'billing_country']);

        Livewire::test(EditUser::class, ['record' => $anna->getRouteKey()])
            ->fillForm(['billing_name' => 'Muster AG', 'billing_street' => ' Rosenweg 3 ', 'billing_postal_code' => '3000', 'billing_city' => 'Bern', 'billing_country' => 'CH'])
            ->call('save')
            ->assertHasNoFormErrors();

        $anna->refresh();
        $this->assertSame(['Muster AG', 'Rosenweg 3', '3000', 'Bern', 'CH'], [$anna->billing_name, $anna->billing_street, $anna->billing_postal_code, $anna->billing_city, $anna->billing_country]);
        $this->assertSame('Muster AG', $anna->billingRecipient()['name']);
    }

    public function test_invoices_cannot_be_created_edited_or_deleted_in_the_panel(): void
    {
        $invoice = Invoice::factory()->create();

        $this->get('/admin/invoices/create')->assertNotFound();
        $this->get("/admin/invoices/{$invoice->id}/edit")->assertNotFound();

        Livewire::test(ListInvoices::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_a_resend_without_user_is_not_offered(): void
    {
        $invoice = Invoice::factory()->create();
        $invoice->user?->delete();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('resend')
            ->assertActionHidden('user')
            ->assertActionVisible('markPaid');
    }

    public function test_actions_appear_in_the_table_too(): void
    {
        $invoice = Invoice::factory()->create();

        Livewire::test(ListInvoices::class)
            ->callAction(TestAction::make('markPaid')->table($invoice), ['paid_at' => now()->toDateString()])
            ->assertHasNoFormErrors();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
    }
}
