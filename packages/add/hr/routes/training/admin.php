<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Training\Http\Controllers\Admin\CertificationController;
use Add\Hr\Training\Http\Controllers\Admin\CourseController;
use Add\Hr\Training\Http\Controllers\Admin\LearningBudgetController;
use Add\Hr\Training\Http\Controllers\Admin\TrainingSessionController;
use Illuminate\Support\Facades\Route;

/*
 * Training: the course catalogue, sessions and enrollments, certifications,
 * and learning budgets with the requests to spend them.
 */

Route::middleware('can:'.Abilities::TRAINING_VIEW)->group(function () {
    Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
    Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show');
    Route::get('training-sessions', [TrainingSessionController::class, 'index'])->name('training-sessions.index');
    Route::get('training-sessions/{session}', [TrainingSessionController::class, 'show'])->name('training-sessions.show');
    Route::get('training-sessions/{session}/enrollments', [TrainingSessionController::class, 'enrollments'])->name('training-sessions.enrollments');
    Route::get('certifications', [CertificationController::class, 'index'])->name('certifications.index');
    Route::get('learning-budgets', [LearningBudgetController::class, 'index'])->name('learning-budgets.index');
    Route::get('employees/{employee}/learning-budget', [LearningBudgetController::class, 'show'])->name('learning-budgets.show');
    Route::get('learning-requests', [LearningBudgetController::class, 'requests'])->name('learning-requests.index');
});

Route::middleware('can:'.Abilities::TRAINING_MANAGE)->group(function () {
    Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
    Route::patch('courses/{course}', [CourseController::class, 'update'])->name('courses.update');

    Route::post('training-sessions', [TrainingSessionController::class, 'store'])->name('training-sessions.store');
    Route::patch('training-sessions/{session}', [TrainingSessionController::class, 'update'])->name('training-sessions.update');
    Route::post('training-sessions/{session}/cancel', [TrainingSessionController::class, 'cancel'])->name('training-sessions.cancel');
    Route::post('training-sessions/{session}/complete', [TrainingSessionController::class, 'complete'])->name('training-sessions.complete');
    Route::post('training-sessions/{session}/enrollments', [TrainingSessionController::class, 'enroll'])->name('training-sessions.enroll');
    Route::post('enrollments/{enrollment}/cancel', [TrainingSessionController::class, 'cancelEnrollment'])->name('enrollments.cancel');

    Route::post('employees/{employee}/certifications', [CertificationController::class, 'store'])->name('certifications.store');
    Route::patch('certifications/{certification}', [CertificationController::class, 'update'])->name('certifications.update');
    Route::delete('certifications/{certification}', [CertificationController::class, 'destroy'])->name('certifications.destroy');

    Route::post('learning-budgets/allocate', [LearningBudgetController::class, 'allocate'])->name('learning-budgets.allocate');
    Route::post('employees/{employee}/learning-budget/adjust', [LearningBudgetController::class, 'adjust'])->name('learning-budgets.adjust');
});
