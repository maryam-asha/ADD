<?php

use Add\Hr\Training\Http\Controllers\SelfService\MyTrainingController;
use Illuminate\Support\Facades\Route;

/*
 * Sessions I may enroll in, my enrollments and certifications, my learning
 * budget and my requests to spend it.
 */
Route::get('training-sessions', [MyTrainingController::class, 'sessions'])->name('training-sessions.index');
Route::post('training-sessions/{session}/enroll', [MyTrainingController::class, 'enroll'])->name('training-sessions.enroll');
Route::get('enrollments', [MyTrainingController::class, 'enrollments'])->name('enrollments.index');
Route::post('enrollments/{enrollment}/cancel', [MyTrainingController::class, 'cancelEnrollment'])->name('enrollments.cancel');
Route::get('certifications', [MyTrainingController::class, 'certifications'])->name('certifications.index');
Route::get('learning-budget', [MyTrainingController::class, 'budget'])->name('learning-budget');
Route::get('learning-requests', [MyTrainingController::class, 'learningRequests'])->name('learning-requests.index');
Route::post('learning-requests', [MyTrainingController::class, 'storeLearningRequest'])->name('learning-requests.store');
Route::post('learning-requests/{learningRequest}/cancel', [MyTrainingController::class, 'cancelLearningRequest'])->name('learning-requests.cancel');
