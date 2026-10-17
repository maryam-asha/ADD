<?php

namespace Add\Hr\Training\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Training\Http\Requests\CertificationRequest;
use Add\Hr\Training\Http\Resources\CertificationResource;
use Add\Hr\Training\Models\Certification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CertificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CertificationResource::collection(
            Certification::query()
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->when($request->query('expires_before'), fn ($q, $day) => $q->whereNotNull('expires_on')->where('expires_on', '<=', $day))
                ->orderByDesc('issued_on')
                ->paginate(100)
        );
    }

    public function store(CertificationRequest $request, Employee $employee): CertificationResource
    {
        $certification = Certification::query()->create([
            ...$request->validated(),
            'employee_id' => $employee->id,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);
        HrAudit::record('certification_recorded', $certification);

        return new CertificationResource($certification);
    }

    public function update(CertificationRequest $request, Certification $certification): JsonResponse
    {
        $certification->update($request->validated());
        HrAudit::record('certification_updated', $certification, ['changes' => array_keys($certification->getChanges())]);

        return response()->json(['message' => __('hr::training.certification_updated')]);
    }

    public function destroy(Certification $certification): Response
    {
        HrAudit::record('certification_deleted', $certification);
        $certification->delete();

        return response()->noContent();
    }
}
