<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveProviderRequestRequest;
use App\Http\Requests\Admin\RejectProviderRequestRequest;
use App\Http\Resources\ProviderRequestResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The approval queue for every provider (docs/decisions/service-providers.md).
 * Office requests are not here: they stay on private-office-requests.
 */
class ProviderRequestController extends Controller
{
    public function __construct(private readonly ProviderRequestService $requests) {}

    /** Pending by default. Filters: `status`, `provider_id`, `type`, `company_id`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString() ?: ProviderRequestStatus::Pending->value;

        return ProviderRequestResource::collection(
            ProviderRequest::query()
                ->with(['provider', 'company', 'submitter'])
                ->where('status', $status)
                ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
                ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
                ->oldest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(ProviderRequest $providerRequest, InvoiceIssuer $invoices): ProviderRequestResource
    {
        $providerRequest->load(['provider', 'company', 'submitter', 'invoice']);
        $providerRequest->setAttribute('invoice_preview', $this->preview($providerRequest, $invoices));

        return new ProviderRequestResource($providerRequest);
    }

    public function approve(ApproveProviderRequestRequest $request, ProviderRequest $providerRequest): JsonResponse
    {
        try {
            $this->requests->approve(
                $providerRequest,
                $request->user(),
                $request->safe()->only(['override_capacity']),
                InvoiceTerms::fromArray($request->validated()),
            );
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.admin.provider_request_approved')]);
    }

    /**
     * What approving would invoice, for a pending billable request; null
     * otherwise. A currency with no rate is not an error here - the
     * preview is in the base currency.
     */
    private function preview(ProviderRequest $providerRequest, InvoiceIssuer $invoices): ?array
    {
        if (! $providerRequest->isPending()) {
            return null;
        }

        // The preview must never break the request view.
        try {
            $draft = $invoices->draftFor($providerRequest);

            return $draft === null ? null : $invoices->quote($draft, new InvoiceTerms)->toArray();
        } catch (InvoicingException|ModelNotFoundException) {
            return null;
        }
    }

    public function reject(RejectProviderRequestRequest $request, ProviderRequest $providerRequest): JsonResponse
    {
        try {
            $this->requests->reject($providerRequest, $request->user(), $request->validated('reason'));
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.admin.provider_request_rejected')]);
    }
}
