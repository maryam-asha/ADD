<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\CompanyInvitationAcceptor;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use App\Services\Notification\ReminderRunner;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * What a provider's portal hears about its companies: money recorded on its
 * invoices, an invited employee joining, a renewal done, a lease ending.
 */
class ProviderNoticesTest extends RenewalsTestCase
{
    use ReadsNotices;

    private Provider $provider;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->provider = Provider::factory()->create();
        $this->account = User::factory()->create();
        $this->account->assignRole('provider');
        $this->provider->users()->attach($this->account->id);
    }

    public function test_a_payment_recorded_on_its_invoice_reaches_the_provider(): void
    {
        $invoice = Invoice::factory()->create(['provider_id' => $this->provider->id, 'billed_to' => Payer::Provider, 'amount' => '100', 'currency' => 'USD']);
        $this->actingAsAdmin();

        app(InvoicePaymentService::class)->record($invoice, ['amount' => '40', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString()], auth()->user());

        $text = $this->noticeText($this->assertNoticed($this->account, 'invoice.payment_recorded', NotificationAudience::Provider), 'en');
        $this->assertSame("40 USD was recorded on invoice #{$invoice->id}. Remaining: 60.", $text['body']);
    }

    public function test_an_invited_employee_joining_reaches_the_provider(): void
    {
        $company = Company::factory()->create(['provider_id' => $this->provider->id, 'legal_name' => 'Acme']);
        $newcomer = User::factory()->create(['name' => 'Omar']);
        CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'phone' => $newcomer->phone,
            'door_access_enabled' => false,
            'provider_request_id' => ProviderRequest::factory()->create(['provider_id' => $this->provider->id, 'type' => ProviderRequestType::CompanyMember, 'company_id' => $company->id])->id,
        ]);

        app(CompanyInvitationAcceptor::class)->acceptFor($newcomer);

        $text = $this->noticeText($this->assertNoticed($this->account, 'provider.employee_joined', NotificationAudience::Provider), 'en');
        $this->assertSame('Omar joined Acme.', $text['body']);
    }

    public function test_a_renewal_done_reaches_the_provider(): void
    {
        $current = $this->assignedMembership($this->provider);

        $renewal = app(MembershipRenewalService::class)->renew($current, null);

        $this->assertSame(
            $renewal->current_period_end->setTimezone('Asia/Damascus')->toDateString(),
            $this->assertNoticed($this->account, 'renewal.done', NotificationAudience::Provider)->data['params']['date'],
        );
    }

    public function test_a_lease_ending_soon_also_reaches_the_provider(): void
    {
        $this->actingAsAdmin();
        $company = $this->clientCompany($this->provider);
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $this->provider->id, 'payer' => 'provider']),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            auth()->user(),
        );
        $lease->update(['ends_on' => now()->addDays(28)->toDateString(), 'auto_renew' => false]);

        app(ReminderRunner::class)->run();

        $this->assertNoticed($this->account, 'lease.ending_soon', NotificationAudience::Provider);
    }
}
