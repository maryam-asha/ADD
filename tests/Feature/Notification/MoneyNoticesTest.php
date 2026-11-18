<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Models\Provider;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * Points a company admin hands out, and an invoice issued: each reaches the
 * people it concerns — the members named, whoever pays the invoice.
 */
class MoneyNoticesTest extends RenewalsTestCase
{
    use ReadsNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_an_allocation_tells_the_members_it_names_but_not_the_admin(): void
    {
        [$company, $admin, $colleague] = $this->companyWithAdmin();
        app(WalletService::class)->creditGeneral(Wallet::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->sole(), 500, WalletTransactionSource::TopUp);
        Sanctum::actingAs($admin, ['member-app']);

        $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => 100,
            'user_ids' => [$admin->id, $colleague->id],
            'expires_at' => now()->addMonth()->toIso8601String(),
        ])->assertCreated();

        $notice = $this->assertNoticed($colleague, 'wallet.company_allocated');
        $this->assertSame('Acme gave you 100 points to use for the café.', $this->noticeText($notice, 'en')['body']);
        $this->assertNotNoticed($admin, 'wallet.company_allocated');
    }

    public function test_a_company_invoice_reaches_its_admins(): void
    {
        [$company, $admin, $colleague] = $this->companyWithAdmin();
        $this->actingAsAdmin();

        app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            auth()->user(),
        );

        $invoice = Invoice::query()->where('company_id', $company->id)->firstOrFail();
        $notice = $this->assertNoticed($admin, 'invoice.issued');
        $this->assertSame('#'.$invoice->id, $notice->data['params']['number']);
        $this->assertSame('USD', $notice->data['params']['currency']);
        $this->assertNotNoticed($colleague, 'invoice.issued');
    }

    public function test_a_provider_invoice_reaches_its_portal(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');
        $provider->users()->attach($account->id);

        $this->assignedMembership($provider);

        $this->assertNoticed($account, 'invoice.issued', NotificationAudience::Provider);
    }

    /** @return array{Company, User, User} a company named Acme, its admin and a plain member */
    private function companyWithAdmin(): array
    {
        $company = Company::factory()->create(['legal_name' => 'Acme']);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        [$admin, $colleague] = [User::factory()->create(), User::factory()->create()];

        foreach ([$admin, $colleague] as $member) {
            $member->assignRole('member');
            Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        }

        $company->members()->attach($admin->id, ['door_access_enabled' => true, 'is_admin' => true]);
        $company->members()->attach($colleague->id, ['door_access_enabled' => true, 'is_admin' => false]);

        return [$company, $admin, $colleague];
    }
}
