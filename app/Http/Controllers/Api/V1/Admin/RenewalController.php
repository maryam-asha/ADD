<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\LeaseRenewalService;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Domain\Renewals\Services\RenewalSettings;
use App\Domain\Renewals\Services\StoppedRenewals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListStoppedRenewalsRequest;
use App\Http\Requests\Admin\UpdateAutoRenewRequest;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Auto-renewal for staff (docs/superpowers/specs/2026-10-11-auto-renewal-design.md):
 * renew by hand (past an overdue invoice, nothing else), turn auto-renew
 * on or off, and list what stopped. All `renewals.manage`.
 */
class RenewalController extends Controller
{
    public function renewMembership(Request $request, Membership $membership, MembershipRenewalService $renewals): JsonResponse
    {
        return $this->attempt(fn () => $renewals->renew($membership, $request->user(), checkOverdue: false));
    }

    public function renewLease(Request $request, OfficeLease $officeLease, LeaseRenewalService $renewals): JsonResponse
    {
        return $this->attempt(fn () => $renewals->extend($officeLease, $request->user(), checkOverdue: false));
    }

    public function membershipAutoRenew(UpdateAutoRenewRequest $request, Membership $membership, RenewalSettings $settings): JsonResponse
    {
        return $this->toggle(fn () => $settings->setForMembership($membership, $request->boolean('auto_renew'), $request->user()));
    }

    public function leaseAutoRenew(UpdateAutoRenewRequest $request, OfficeLease $officeLease, RenewalSettings $settings): JsonResponse
    {
        return $this->toggle(fn () => $settings->setForLease($officeLease, $request->boolean('auto_renew'), $request->user()));
    }

    /** Stopped subscriptions and leases, soonest end first. */
    public function stopped(ListStoppedRenewalsRequest $request, StoppedRenewals $stopped): JsonResponse
    {
        $type = $request->input('type');
        $reason = $request->input('reason');
        $rows = collect()
            ->concat($type === 'lease' ? [] : $this->stoppedMemberships($stopped->memberships($reason)))
            ->concat($type === 'membership' ? [] : $this->stoppedLeases($stopped->leases($reason)))
            ->when($request->filled('provider_id'), fn (Collection $c) => $c->filter(fn ($row) => ($row['provider']['id'] ?? null) === $request->integer('provider_id')))
            ->sortBy('ends_at')
            ->values();

        $perPage = $request->integer('per_page', 15);
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
            ],
        ]);
    }

    /**
     * @param  EloquentCollection<int, Membership>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    private function stoppedMemberships(EloquentCollection $memberships): Collection
    {
        $companies = Company::query()->with('provider')->whereIn('id', $memberships->pluck('owner_id'))->get()->keyBy('id');

        return $memberships
            ->map(fn (Membership $m) => $this->row('membership', $m->id, $companies->get($m->owner_id), $m->renewal_stop_reason, $m->current_period_end->toIso8601String(), $m->current_period_end->isPast()));
    }

    /**
     * @param  EloquentCollection<int, OfficeLease>  $leases
     * @return Collection<int, array<string, mixed>>
     */
    private function stoppedLeases(EloquentCollection $leases): Collection
    {
        return $leases
            ->load('company.provider')
            ->map(fn (OfficeLease $l) => $this->row('lease', $l->id, $l->company, $l->renewal_stop_reason, $l->ends_on->toDateString(), $l->ends_on->toDateString() < now()->toDateString()));
    }

    /** @return array<string, mixed> */
    private function row(string $type, int $id, ?Company $company, $reason, string $endsAt, bool $hasEnded): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'company' => $company === null ? null : ['id' => $company->id, 'legal_name' => $company->legal_name],
            'provider' => $company?->provider === null ? null : ['id' => $company->provider->id, 'name' => $company->provider->name],
            'reason' => $reason->value,
            'reason_label' => $reason->label(),
            'ends_at' => $endsAt,
            'has_ended' => $hasEnded,
        ];
    }

    private function attempt(callable $renew): JsonResponse
    {
        try {
            $renew();
        } catch (RenewalRefused $e) {
            return response()->json(['message' => __('api.renewal.refused', ['reason' => $e->reason->label()])], 422);
        } catch (RenewalException|InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.renewed')]);
    }

    private function toggle(callable $set): JsonResponse
    {
        try {
            $set();
        } catch (RenewalException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }
}
