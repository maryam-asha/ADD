<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\CommunityMember;
use App\Http\Requests\Admin\StoreCommunityMemberRequest;
use App\Http\Requests\Admin\UpdateCommunityMemberRequest;
use App\Http\Resources\CommunityMemberResource;

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

    public function store(StoreCommunityMemberRequest $request): CommunityMemberResource
    {
        return new CommunityMemberResource(CommunityMember::create($request->validated()));
    }

    public function update(UpdateCommunityMemberRequest $request, CommunityMember $communityMember): CommunityMemberResource
    {
        $communityMember->update($request->validated());

        return new CommunityMemberResource($communityMember);
    }
}
