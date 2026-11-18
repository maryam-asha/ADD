<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Recruitment\Http\Requests\UpdateCandidateRequest;
use Add\Hr\Recruitment\Http\Resources\CandidateResource;
use Add\Hr\Recruitment\Models\Candidate;
use Add\Hr\Recruitment\Services\CvVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CandidateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        return CandidateResource::collection(
            Candidate::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', '%'.mb_strtolower($search).'%')
                    ->orWhere('phone', 'like', "%{$search}%")))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Candidate $candidate): CandidateResource
    {
        return new CandidateResource($candidate->load('applications.opening'));
    }

    public function update(UpdateCandidateRequest $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        $candidate->update($data);
        HrAudit::record('candidate_updated', $candidate, ['changes' => array_keys($candidate->getChanges())]);

        return response()->json(['message' => __('hr::recruitment.candidate_updated')]);
    }

    /**
     * Erases a candidate and everything about them, CVs included (a
     * request to be forgotten). An employee's record stays.
     */
    public function destroy(Candidate $candidate, CvVault $cvs): Response
    {
        $candidate->applications()->pluck('cv_path')->each(fn (?string $path) => $cvs->delete($path));
        HrAudit::record('candidate_erased', $candidate);
        $candidate->delete();

        return response()->noContent();
    }
}
