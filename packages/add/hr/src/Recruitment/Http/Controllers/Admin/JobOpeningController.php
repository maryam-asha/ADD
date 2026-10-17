<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Recruitment\Http\Requests\StoreJobOpeningRequest;
use Add\Hr\Recruitment\Http\Requests\UpdateJobOpeningRequest;
use Add\Hr\Recruitment\Http\Resources\JobOpeningResource;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Recruitment\Services\JobOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class JobOpeningController extends Controller
{
    public function __construct(private readonly JobOpeningService $openings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return JobOpeningResource::collection(
            JobOpening::query()->with('workLocation')->withCount('applications')
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('requisition_id'), fn ($q, $id) => $q->where('requisition_id', $id))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function store(StoreJobOpeningRequest $request, Requisition $requisition): JobOpeningResource
    {
        $opening = $this->openings->create($requisition, $request->validated(), $request->user()?->getAuthIdentifier());

        return new JobOpeningResource($opening->refresh()->load('workLocation'));
    }

    public function show(JobOpening $opening): JobOpeningResource
    {
        return new JobOpeningResource($opening->load('workLocation')->loadCount('applications'));
    }

    public function update(UpdateJobOpeningRequest $request, JobOpening $opening): JsonResponse
    {
        $opening->update($request->validated());

        return response()->json(['message' => __('hr::recruitment.opening_updated')]);
    }

    public function open(JobOpening $opening): JsonResponse
    {
        $this->openings->open($opening);

        return response()->json(['message' => __('hr::recruitment.opening_opened')]);
    }

    public function close(JobOpening $opening): JsonResponse
    {
        $this->openings->close($opening);

        return response()->json(['message' => __('hr::recruitment.opening_closed_now')]);
    }
}
