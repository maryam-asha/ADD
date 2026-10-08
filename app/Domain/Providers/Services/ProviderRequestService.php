<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Support\Facades\DB;

/**
 * The one path for provider requests (docs/decisions/service-providers.md):
 * a provider submits and may cancel while pending; a reviewer approves —
 * which hands the request to its type's handler under a row lock — or
 * rejects with a reason.
 */
class ProviderRequestService
{
    public function submit(Provider $provider, User $submitter, ProviderRequestType $type, ?int $companyId, array $payload): ProviderRequest
    {
        $request = ProviderRequest::create([
            'provider_id' => $provider->id,
            'submitted_by' => $submitter->id,
            'type' => $type,
            'company_id' => $companyId,
            'payload' => $payload,
            'status' => ProviderRequestStatus::Pending,
        ]);

        activity()->causedBy($submitter)->performedOn($request)
            ->withProperties(['type' => $type->value, 'company_id' => $companyId])
            ->log('provider_request_submitted');

        return $request;
    }

    public function cancel(ProviderRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $locked = ProviderRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new ProviderRequestException('api.provider.request_not_pending', 409);
            }

            $locked->forceFill(['status' => ProviderRequestStatus::Cancelled])->save();
        });
    }

    /**
     * A pending request asking for the same thing: the same employee, the
     * same admin, or any plan assignment for the same company. Top-ups and
     * plan designs may be asked for more than once.
     */
    public function duplicateExists(ProviderRequestType $type, ?int $companyId, array $payload): bool
    {
        $key = match ($type) {
            ProviderRequestType::CompanyMember => ['payload->phone', $payload['phone'] ?? null],
            ProviderRequestType::CompanyAdmin => ['payload->user_id', $payload['user_id'] ?? null],
            ProviderRequestType::PlanAssignment => null,
            default => false,
        };

        if ($key === false) {
            return false;
        }

        return ProviderRequest::query()
            ->pending()
            ->where('type', $type)
            ->where('company_id', $companyId)
            ->when($key !== null, fn ($query) => $query->where($key[0], $key[1]))
            ->exists();
    }
}
