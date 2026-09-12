<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveDefaultBranch
{
    /**
     * Injects the active/default branch_id into the request.
     *
     * Order of precedence:
     * 1. X-Branch-Id header (explicitly provided by caller)
     * 2. branch_id already in request parameters/body/query (e.g. spatial module forms & explicit queries)
     * 3. Default branch (Branch::defaultId()) for mutation requests or when required
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Explicit header takes highest precedence
        if ($request->hasHeader('X-Branch-Id')) {
            $branchId = (int) $request->header('X-Branch-Id');
            $request->merge(['branch_id' => $branchId]);
            $request->query->set('branch_id', $branchId);

            return $next($request);
        }

        // 2. If branch_id is already explicitly present (body or query), preserve it untouched
        if ($request->filled('branch_id')) {
            return $next($request);
        }

        // 3. For mutation requests (POST/PUT/PATCH), inject default branch so FormRequests
        // and validation rules (e.g. StoreCompanyRequest, OpenCashRegisterSessionRequest) pass seamlessly
        if (! $request->isMethodSafe()) {
            $defaultId = Branch::defaultId();
            if ($defaultId) {
                $request->merge(['branch_id' => $defaultId]);
            }
        }

        return $next($request);
    }
}
