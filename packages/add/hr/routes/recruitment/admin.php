<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Recruitment\Http\Controllers\Admin\ApplicationController;
use Add\Hr\Recruitment\Http\Controllers\Admin\CandidateController;
use Add\Hr\Recruitment\Http\Controllers\Admin\InterviewController;
use Add\Hr\Recruitment\Http\Controllers\Admin\JobOpeningController;
use Add\Hr\Recruitment\Http\Controllers\Admin\OfferController;
use Add\Hr\Recruitment\Http\Controllers\Admin\PipelineStageController;
use Add\Hr\Recruitment\Http\Controllers\Admin\RequisitionController;
use Illuminate\Support\Facades\Route;

/*
 * Recruitment: requisitions, openings, candidates and their applications
 * through the pipeline, interviews and offers. Hiring a candidate creates an
 * employee, so it also needs hr.employees.create.
 */

Route::middleware('can:'.Abilities::RECRUITMENT_VIEW)->group(function () {
    Route::get('pipeline-stages', [PipelineStageController::class, 'index'])->name('pipeline-stages.index');
    Route::get('requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::get('job-openings', [JobOpeningController::class, 'index'])->name('job-openings.index');
    Route::get('job-openings/{opening}', [JobOpeningController::class, 'show'])->name('job-openings.show');
    Route::get('candidates', [CandidateController::class, 'index'])->name('candidates.index');
    Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show');
    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/cv-link', [ApplicationController::class, 'cvLink'])->name('applications.cv-link');
    Route::get('interviews', [InterviewController::class, 'index'])->name('interviews.index');
    Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
});

Route::middleware('can:'.Abilities::RECRUITMENT_MANAGE)->group(function () {
    Route::post('pipeline-stages', [PipelineStageController::class, 'store'])->name('pipeline-stages.store');
    Route::patch('pipeline-stages/{stage}', [PipelineStageController::class, 'update'])->name('pipeline-stages.update');

    Route::post('requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::post('requisitions/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('requisitions.cancel');
    Route::post('requisitions/{requisition}/close', [RequisitionController::class, 'close'])->name('requisitions.close');

    Route::post('requisitions/{requisition}/job-openings', [JobOpeningController::class, 'store'])->name('job-openings.store');
    Route::patch('job-openings/{opening}', [JobOpeningController::class, 'update'])->name('job-openings.update');
    Route::post('job-openings/{opening}/open', [JobOpeningController::class, 'open'])->name('job-openings.open');
    Route::post('job-openings/{opening}/close', [JobOpeningController::class, 'close'])->name('job-openings.close');

    Route::patch('candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update');
    Route::delete('candidates/{candidate}', [CandidateController::class, 'destroy'])->name('candidates.destroy');

    Route::post('job-openings/{opening}/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::post('applications/{application}/move', [ApplicationController::class, 'move'])->name('applications.move');
    Route::post('applications/{application}/reject', [ApplicationController::class, 'reject'])->name('applications.reject');
    Route::post('applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])->name('applications.withdraw');
    Route::post('applications/{application}/hire', [ApplicationController::class, 'hire'])
        ->middleware('can:'.Abilities::EMPLOYEES_CREATE)->name('applications.hire');

    Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->name('interviews.store');
    Route::post('interviews/{interview}/complete', [InterviewController::class, 'complete'])->name('interviews.complete');
    Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('interviews.cancel');

    Route::post('applications/{application}/offers', [OfferController::class, 'store'])->name('offers.store');
    Route::post('offers/{offer}/accept', [OfferController::class, 'accept'])->name('offers.accept');
    Route::post('offers/{offer}/decline', [OfferController::class, 'decline'])->name('offers.decline');
    Route::post('offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->name('offers.withdraw');
});
