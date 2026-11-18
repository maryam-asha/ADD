<?php

namespace Add\Hr\Http\Middleware;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\LocalDay;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `hr.employee`: the signed-in account has an employee record and is
 * employed today. This is the whole permission for self-service — whatever
 * the account's roles are. Puts the employee on the request.
 */
class EnsureActiveEmployee
{
    public const ATTRIBUTE = 'hr_employee';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $employee = $user === null ? null : Employee::forUser($user);

        if ($employee === null || ! $employee->isEmployedOn(LocalDay::today())) {
            return response()->json(['message' => __('hr::system.not_an_employee')], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $employee);

        return $next($request);
    }
}
