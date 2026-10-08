<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Moves a company to another provider, or to ADD directly (null). The old
 * provider's pending requests on it are cancelled: they were asked by
 * someone who no longer manages it.
 */
class CompanyProviderController extends Controller
{
    use LogsSensitiveActions;

    public function update(UpdateCompanyProviderRequest $request, Company $company, ProviderRequestService $requests): JsonResponse
    {
        $before = $company->provider_id;
        $after = $request->validated('provider_id') === null ? null : (int) $request->validated('provider_id');

        DB::transaction(function () use ($company, $before, $after, $requests) {
            if ($before !== null && $before !== $after) {
                $requests->cancelPending(
                    ProviderRequest::query()->where('provider_id', $before)->where('company_id', $company->id),
                    'company_transferred',
                );
            }

            $company->update(['provider_id' => $after]);
        });

        $this->logSensitiveAction('company_provider_changed', $company, ['before' => $before, 'after' => $after]);

        return response()->json(['message' => __('api.admin.company_provider_updated')]);
    }
}
