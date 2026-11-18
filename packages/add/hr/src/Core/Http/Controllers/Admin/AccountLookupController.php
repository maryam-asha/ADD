<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The hire form's first step: find the person's existing account, so a
 * member who joins the team is hired on the account they already have.
 * Each row says whether that account is already an employee.
 */
class AccountLookupController extends Controller
{
    private const LIMIT = 10;

    public function index(Request $request, AccountProvisioner $accounts): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']]);

        $rows = $accounts->search((string) $request->query('q'), self::LIMIT);
        $employees = Employee::query()->whereIn('user_id', array_column($rows, 'id'))->pluck('id', 'user_id');

        return response()->json([
            'data' => array_map(fn (array $row) => [...$row, 'employee_id' => $employees[$row['id']] ?? null], $rows),
        ]);
    }
}
