<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Pricing\Services\MemberCoupons;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ListMemberCouponsRequest;
use App\Http\Resources\MemberCouponResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET member/coupons — "my coupons": the codes given to this member that
 * they can use now; with `purpose` and a space, plan or event, only those
 * that apply to it.
 */
class MemberCouponController extends Controller
{
    public function __invoke(ListMemberCouponsRequest $request, MemberCoupons $coupons): AnonymousResourceCollection
    {
        return MemberCouponResource::collection($coupons->for($request->user(), $request->target()));
    }
}
