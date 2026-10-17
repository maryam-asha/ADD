<?php

namespace Add\Hr\Recruitment\Http\Controllers\Public;

use Add\Hr\Contracts\HrClock;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Enums\OpeningVisibility;
use Add\Hr\Recruitment\Http\Requests\ApplyRequest;
use Add\Hr\Recruitment\Http\Resources\PublicJobOpeningResource;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Services\ApplicationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The careers page: public openings taking applications, and applying to
 * one. An applicant learns nothing about other candidates, nor whether
 * their email was already known.
 */
class CareersController extends Controller
{
    public function __construct(private readonly HrClock $clock) {}

    public function index(): AnonymousResourceCollection
    {
        return PublicJobOpeningResource::collection($this->published()->with('workLocation')->latest('opened_at')->get());
    }

    public function show(string $opening): PublicJobOpeningResource
    {
        return new PublicJobOpeningResource($this->published()->with('workLocation')->findOrFail($opening));
    }

    public function apply(ApplyRequest $request, string $opening, ApplicationService $applications): JsonResponse
    {
        $applications->apply(
            $this->published()->findOrFail($opening),
            $request->safe()->only(['name', 'email', 'phone']),
            $request->file('cv'),
            CandidateSource::Careers,
            ['cover_letter' => $request->validated('cover_letter'), 'consent' => true],
            null,
        );

        return response()->json(['message' => __('hr::recruitment.application_received')], 201);
    }

    private function published()
    {
        return JobOpening::query()
            ->where('visibility', OpeningVisibility::Public->value)
            ->accepting(CarbonImmutable::now($this->clock->timezone()));
    }
}
