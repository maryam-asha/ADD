<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Requests\Admin\StorePrivateOfficeRequestRequest;
use App\Http\Requests\Admin\UpdatePrivateOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use App\Support\LikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrivateOfficeRequestController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return PrivateOfficeRequest::class;
    }

    protected function resourceClass(): string
    {
        return PrivateOfficeRequestResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    /**
     * Newest first. Each request names the provider that brought it; the list
     * filters by provider, status, and a search over the prospect, the contact
     * and the existing company's name.
     */
    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $request->validate([
            'provider_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(PrivateOfficeRequestStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query->with(['provider', 'company'])
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $like = LikeTerm::contains($request->string('search'));
                $q->where(fn (Builder $w) => $w->whereRaw("prospect_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('company', fn (Builder $c) => $c->whereRaw("legal_name LIKE ? ESCAPE '!'", [$like])));
            })
            ->latest('id');
    }

    public function store(StorePrivateOfficeRequestRequest $request): PrivateOfficeRequestResource
    {
        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
        ]));
    }

    public function update(UpdatePrivateOfficeRequestRequest $request, PrivateOfficeRequest $privateOfficeRequest): JsonResponse
    {
        $privateOfficeRequest->update($request->validated());

        return response()->json(['message' => __('api.admin.private_office_request_updated')]);
    }
}
