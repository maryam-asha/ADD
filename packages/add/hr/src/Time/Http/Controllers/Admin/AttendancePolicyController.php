<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Time\Http\Requests\StoreAttendancePolicyRequest;
use Add\Hr\Time\Http\Requests\UpdateAttendancePolicyRequest;
use Add\Hr\Time\Http\Resources\AttendancePolicyResource;
use Add\Hr\Time\Models\AttendancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Without a policy in force, the hr.attendance defaults apply.
 */
class AttendancePolicyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AttendancePolicyResource::collection(AttendancePolicy::query()->orderBy('id')->get());
    }

    public function store(StoreAttendancePolicyRequest $request): AttendancePolicyResource
    {
        return new AttendancePolicyResource(AttendancePolicy::query()->create($request->validated())->refresh());
    }

    public function show(AttendancePolicy $attendancePolicy): AttendancePolicyResource
    {
        return new AttendancePolicyResource($attendancePolicy);
    }

    public function update(UpdateAttendancePolicyRequest $request, AttendancePolicy $attendancePolicy): JsonResponse
    {
        $attendancePolicy->update($request->validated());

        return response()->json(['message' => __('hr::time.policy_updated')]);
    }

    public function destroy(AttendancePolicy $attendancePolicy): Response
    {

        $attendancePolicy->delete();

        return response()->noContent();
    }
}
