<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Payroll\Http\Requests\CloseRateVersionRequest;
use Add\Hr\Payroll\Http\Requests\StoreRateTableRequest;
use Add\Hr\Payroll\Http\Requests\StoreRateVersionRequest;
use Add\Hr\Payroll\Http\Requests\UpdateRateTableRequest;
use Add\Hr\Payroll\Http\Resources\RateTableResource;
use Add\Hr\Payroll\Models\RateTable;
use Add\Hr\Payroll\Models\RateTableVersion;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Legal rates and scales, entered as data. Versions never overlap and are
 * never rewritten: a change of law is a new version from its date. Every
 * write is audited.
 */
class RateTableController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RateTableResource::collection(RateTable::query()->with('versions')->orderBy('code')->get());
    }

    public function store(StoreRateTableRequest $request): RateTableResource
    {
        $table = RateTable::query()->create($request->validated());
        HrAudit::record('rate_table_created', $table);

        return new RateTableResource($table->load('versions'));
    }

    public function show(RateTable $rateTable): RateTableResource
    {
        return new RateTableResource($rateTable->load('versions'));
    }

    public function update(UpdateRateTableRequest $request, RateTable $rateTable): JsonResponse
    {
        $rateTable->update($request->validated());

        return response()->json(['message' => __('hr::payroll.rate_table_updated')]);
    }

    public function destroy(RateTable $rateTable): Response
    {
        if ($rateTable->versions()->exists()) {
            HrException::fail('hr::payroll.rate_table_in_use', [], 409);
        }

        $rateTable->delete();

        return response()->noContent();
    }

    public function storeVersion(StoreRateVersionRequest $request, RateTable $rateTable): JsonResponse
    {
        DB::transaction(function () use ($request, $rateTable) {
            $from = $request->validated('effective_from');
            $to = $request->validated('effective_to');

            $overlaps = $rateTable->versions()->getQuery()
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
                ->when($to !== null, fn ($q) => $q->where('effective_from', '<=', $to))
                ->exists();

            if ($overlaps) {
                HrException::fail('hr::payroll.rate_version_overlap');
            }

            $version = $rateTable->versions()->create($request->validated());
            HrAudit::record('rate_table_version_added', $rateTable, ['version_id' => $version->id]);
        });

        return (new RateTableResource($rateTable->load('versions')))->response()->setStatusCode(201);
    }

    public function closeVersion(CloseRateVersionRequest $request, RateTableVersion $version): JsonResponse
    {
        if ($request->validated('effective_to') < $version->effective_from->toDateString()) {
            HrException::fail('hr::payroll.rate_version_overlap');
        }

        $version->update($request->validated());
        HrAudit::record('rate_table_version_closed', $version, $request->validated());

        return response()->json(['message' => __('hr::payroll.rate_table_updated')]);
    }
}
