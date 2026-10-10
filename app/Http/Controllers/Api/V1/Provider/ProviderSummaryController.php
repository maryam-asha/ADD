<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\ProviderRequest;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The portal's home page: a handful of counts and what is still owed, per
 * currency (invoices are never summed across currencies). Reports proper
 * are a later phase (docs/decisions/service-providers.md).
 */
class ProviderSummaryController extends Controller
{
    use ActsForProvider;

    public function show(Request $request): JsonResponse
    {
        $providerId = $this->provider($request)->id;
        $companyIds = Company::query()->where('provider_id', $providerId)->pluck('id');

        return response()->json(['data' => [
            'companies' => [
                'total' => $companyIds->count(),
                'active' => Company::query()->whereIn('id', $companyIds)->where('status', CompanyStatus::Active)->count(),
            ],
            'employees' => DB::table('company_user')->whereIn('company_id', $companyIds)->distinct()->count('user_id'),
            'pending_requests' => ProviderRequest::query()
                ->where('provider_id', $providerId)
                ->where('status', ProviderRequestStatus::Pending)
                ->count(),
            'open_office_requests' => PrivateOfficeRequest::query()
                ->where('provider_id', $providerId)
                ->where('status', '!=', PrivateOfficeRequestStatus::Contracted)
                ->count(),
            'invoices' => $this->invoices($providerId),
        ]]);
    }

    /** @return array<string, mixed> */
    private function invoices(int $providerId): array
    {
        $open = fn () => Invoice::query()
            ->where('provider_id', $providerId)
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid]);

        $outstanding = $open()
            ->selectRaw('currency')
            ->selectRaw('SUM(CASE WHEN billed_to = ? THEN amount - paid_amount ELSE 0 END) AS owed_by_provider', [Payer::Provider->value])
            ->selectRaw('SUM(CASE WHEN billed_to = ? THEN amount - paid_amount ELSE 0 END) AS owed_by_companies', [Payer::Company->value])
            ->selectRaw('SUM(CASE WHEN due_at < ? THEN amount - paid_amount ELSE 0 END) AS overdue', [now()->toDateString()])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'owed_by_provider' => number_format((float) $row->owed_by_provider, 2, '.', ''),
                'owed_by_companies' => number_format((float) $row->owed_by_companies, 2, '.', ''),
                'overdue' => number_format((float) $row->overdue, 2, '.', ''),
            ])
            ->values();

        return [
            'open' => $open()->count(),
            'overdue' => Invoice::query()->where('provider_id', $providerId)->overdue()->count(),
            'outstanding' => $outstanding,
        ];
    }
}
