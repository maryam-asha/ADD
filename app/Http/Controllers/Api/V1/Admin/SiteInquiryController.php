<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Enums\SiteInquiryStatus;
use App\Domain\Ecosystem\Models\SiteInquiry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteInquiryRequest;
use App\Http\Resources\SiteInquiryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SiteInquiryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SiteInquiry::with('assignedTo')->latest('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('assigned_to_user_id')) {
            $query->where('assigned_to_user_id', $request->input('assigned_to_user_id'));
        }

        $perPage = $request->integer('per_page', 15);

        return SiteInquiryResource::collection($query->paginate($perPage));
    }

    public function show(SiteInquiry $siteInquiry): SiteInquiryResource
    {
        return new SiteInquiryResource($siteInquiry->load('assignedTo'));
    }

    public function update(UpdateSiteInquiryRequest $request, SiteInquiry $siteInquiry): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['status']) && $data['status'] === SiteInquiryStatus::Resolved->value && ! $siteInquiry->resolved_at) {
            $data['resolved_at'] = now();
        }

        $siteInquiry->update($data);

        return response()->json([
            'message' => __('api.admin.inquiry_updated'),
        ]);
    }
}
