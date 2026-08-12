<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\CommunityMember;
use App\Http\Requests\Admin\StoreCommunityMemberRequest;
use App\Http\Requests\Admin\UpdateCommunityMemberRequest;
use App\Http\Resources\CommunityMemberResource;
use Illuminate\Http\JsonResponse;

class CommunityMemberController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return CommunityMember::class;
    }

    protected function resourceClass(): string
    {
        return CommunityMemberResource::class;
    }

    /**
     * `order`/`published` are set explicitly here, not left to the
     * migration's column defaults — Eloquent doesn't re-fetch DB-side
     * defaults into an unrefreshed model, so omitting either would
     * otherwise come back `null` in this very response even though the DB
     * row is correctly `0`/`true` (the same lesson already documented for
     * `CompanyController::store` and `UserFactory`).
     */
    public function store(StoreCommunityMemberRequest $request): CommunityMemberResource
    {
        return new CommunityMemberResource(CommunityMember::create(array_merge(
            ['order' => 0, 'published' => true],
            $request->validated()
        )));
    }

    public function update(UpdateCommunityMemberRequest $request, CommunityMember $communityMember): JsonResponse
    {
        $communityMember->update($request->validated());

        return response()->json(['message' => __('api.admin.community_member_updated')]);
    }
}
