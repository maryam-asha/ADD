<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Benefits\Http\Controllers\Admin\BenefitAssignmentController;
use Add\Hr\Benefits\Http\Controllers\Admin\BenefitController;
use Add\Hr\Benefits\Http\Controllers\Admin\BenefitGrantController;
use Illuminate\Support\Facades\Route;

/*
 * Benefits: what each one is worth, who holds it (assignments), and what
 * was granted. The employee cost report reads pay, so it needs
 * hr.payroll.view.
 */

Route::middleware('can:'.Abilities::BENEFITS_VIEW)->group(function () {
    Route::apiResource('benefits', BenefitController::class)->only(['index', 'show']);
    Route::get('benefits/{benefit}/assignments', [BenefitAssignmentController::class, 'index'])->name('benefits.assignments.index');
    Route::get('benefit-grants', [BenefitGrantController::class, 'index'])->name('benefit-grants.index');
    Route::get('employees/{employee}/benefits', [BenefitController::class, 'forEmployee'])->name('employees.benefits');
});

Route::middleware('can:'.Abilities::BENEFITS_MANAGE)->group(function () {
    Route::apiResource('benefits', BenefitController::class)->only(['store', 'update', 'destroy']);
    Route::post('benefits/{benefit}/assignments', [BenefitAssignmentController::class, 'store'])->name('benefits.assignments.store');
    Route::patch('benefit-assignments/{assignment}', [BenefitAssignmentController::class, 'end'])->name('benefit-assignments.end');
    Route::delete('benefit-assignments/{assignment}', [BenefitAssignmentController::class, 'destroy'])->name('benefit-assignments.destroy');
    Route::post('benefit-grants/run', [BenefitGrantController::class, 'run'])->name('benefit-grants.run');
    Route::post('benefit-grants/{grant}/retry', [BenefitGrantController::class, 'retry'])->name('benefit-grants.retry');
    Route::post('benefit-grants/{grant}/cancel', [BenefitGrantController::class, 'cancel'])->name('benefit-grants.cancel');
});

Route::get('reports/employee-cost', [BenefitGrantController::class, 'employeeCost'])
    ->middleware('can:'.Abilities::PAYROLL_VIEW)->name('reports.employee-cost');
