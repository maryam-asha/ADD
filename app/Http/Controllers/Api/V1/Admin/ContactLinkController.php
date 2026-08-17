<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Http\Requests\Admin\StoreContactLinkRequest;
use App\Http\Requests\Admin\UpdateContactLinkRequest;
use App\Http\Resources\ContactLinkResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactLinkController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return ContactLink::class;
    }

    protected function resourceClass(): string
    {
        return ContactLinkResource::class;
    }

    /**
     * `contact_links` orders by `sort_order`, not the base class's
     * hardcoded `order` column — skip the base ordering and add ours
     * through the existing filter hook instead of duplicating index().
     */
    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->orderBy('sort_order');
    }

    /**
     * `sort_order`/`is_visible` are set explicitly here, not left to the
     * migration's column defaults — Eloquent doesn't re-fetch DB-side
     * defaults into an unrefreshed model, so omitting either would
     * otherwise come back `null` in this very response even though the DB
     * row is correctly `0`/`true` (the same lesson already documented for
     * `FounderController::store`/`PartnerController::store`).
     */
    public function store(StoreContactLinkRequest $request): ContactLinkResource
    {
        return new ContactLinkResource(ContactLink::create(array_merge(
            ['sort_order' => 0, 'is_visible' => true],
            $request->validated()
        )));
    }

    public function update(UpdateContactLinkRequest $request, ContactLink $contactLink): JsonResponse
    {
        $contactLink->update($request->validated());

        return response()->json(['message' => __('api.admin.contact_link_updated')]);
    }
}
