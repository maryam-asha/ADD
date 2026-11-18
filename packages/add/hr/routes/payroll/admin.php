<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Payroll\Http\Controllers\Admin\CompensationController;
use Add\Hr\Payroll\Http\Controllers\Admin\EndOfServiceController;
use Add\Hr\Payroll\Http\Controllers\Admin\LoanController;
use Add\Hr\Payroll\Http\Controllers\Admin\PayComponentController;
use Add\Hr\Payroll\Http\Controllers\Admin\PayrollRunController;
use Add\Hr\Payroll\Http\Controllers\Admin\RateTableController;
use Illuminate\Support\Facades\Route;

/*
 * Payroll. Reading pay, runs and slips needs hr.payroll.view; components
 * and legal rate tables are settings; each step of a run has its own
 * ability, so preparing, approving and paying can sit with different
 * people.
 */

Route::middleware('can:'.Abilities::PAYROLL_VIEW)->group(function () {
    Route::get('payroll-calculators', [PayComponentController::class, 'calculators'])->name('payroll-calculators.index');
    Route::apiResource('pay-components', PayComponentController::class)->only(['index', 'show'])->parameters(['pay-components' => 'payComponent']);
    Route::apiResource('rate-tables', RateTableController::class)->only(['index', 'show'])->parameters(['rate-tables' => 'rateTable']);
    Route::get('employees/{employee}/compensation', [CompensationController::class, 'index'])->name('employees.compensation.index');

    Route::get('payroll-runs', [PayrollRunController::class, 'index'])->name('payroll-runs.index');
    Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])->name('payroll-runs.show');
    Route::get('payroll-runs/{payrollRun}/payslips', [PayrollRunController::class, 'payslips'])->name('payroll-runs.payslips');
    Route::get('payroll-runs/{payrollRun}/variance', [PayrollRunController::class, 'variance'])->name('payroll-runs.variance');
    Route::get('payroll-runs/{payrollRun}/adjustments', [PayrollRunController::class, 'adjustments'])->name('payroll-runs.adjustments.index');
    Route::get('payslips/{payslip}', [PayrollRunController::class, 'payslip'])->name('payslips.show');

    Route::get('employees/{employee}/end-of-service', [EndOfServiceController::class, 'show'])->name('employees.end-of-service');
    Route::get('reports/end-of-service-liability', [EndOfServiceController::class, 'liability'])->name('reports.end-of-service-liability');
});

Route::middleware('can:'.Abilities::SETTINGS_MANAGE)->group(function () {
    Route::apiResource('pay-components', PayComponentController::class)->only(['store', 'update', 'destroy'])->parameters(['pay-components' => 'payComponent']);
    Route::apiResource('rate-tables', RateTableController::class)->only(['store', 'update', 'destroy'])->parameters(['rate-tables' => 'rateTable']);
    Route::post('rate-tables/{rateTable}/versions', [RateTableController::class, 'storeVersion'])->name('rate-tables.versions.store');
    Route::patch('rate-table-versions/{version}', [RateTableController::class, 'closeVersion'])->name('rate-table-versions.close');
});

Route::put('employees/{employee}/compensation', [CompensationController::class, 'change'])
    ->middleware('can:'.Abilities::COMPENSATION_MANAGE)->name('employees.compensation.change');

Route::middleware('can:'.Abilities::PAYROLL_MANAGE)->group(function () {
    Route::post('payroll-runs', [PayrollRunController::class, 'store'])->name('payroll-runs.store');
    Route::delete('payroll-runs/{payrollRun}', [PayrollRunController::class, 'destroy'])->name('payroll-runs.destroy');
    Route::post('payroll-runs/{payrollRun}/calculate', [PayrollRunController::class, 'calculate'])->name('payroll-runs.calculate');
    Route::post('payroll-runs/{payrollRun}/review', [PayrollRunController::class, 'review'])->name('payroll-runs.review');
    Route::post('payroll-runs/{payrollRun}/reconcile', [PayrollRunController::class, 'reconcile'])->name('payroll-runs.reconcile');
    Route::post('payroll-runs/{payrollRun}/adjustments', [PayrollRunController::class, 'addAdjustment'])->name('payroll-runs.adjustments.store');
    Route::delete('payroll-adjustments/{adjustment}', [PayrollRunController::class, 'removeAdjustment'])->name('payroll-adjustments.destroy');
});

Route::post('payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])
    ->middleware('can:'.Abilities::PAYROLL_APPROVE)->name('payroll-runs.approve');

Route::middleware('can:'.Abilities::PAYROLL_PAY)->group(function () {
    Route::post('payroll-runs/{payrollRun}/pay', [PayrollRunController::class, 'pay'])->name('payroll-runs.pay');
    Route::get('payroll-runs/{payrollRun}/journal', [PayrollRunController::class, 'journal'])->name('payroll-runs.journal');
    Route::post('payroll-runs/{payrollRun}/post', [PayrollRunController::class, 'post'])->name('payroll-runs.post');
});

Route::middleware('can:'.Abilities::LOANS_MANAGE)->group(function () {
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
    Route::post('employees/{employee}/loans', [LoanController::class, 'store'])->name('employees.loans.store');
    Route::post('loans/{loan}/settle', [LoanController::class, 'settle'])->name('loans.settle');
});
