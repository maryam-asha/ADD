<?php

use Add\Hr\Recruitment\Http\Controllers\SelfService\MyRecruitmentController;
use Illuminate\Support\Facades\Route;

/*
 * Open jobs and my applications to them, the requisitions I file as a
 * hiring manager, and the interviews I conduct with my scorecard. Only an
 * interviewer of an interview may score it (InterviewService::score).
 */
Route::get('job-openings', [MyRecruitmentController::class, 'openings'])->name('job-openings.index');
Route::post('job-openings/{opening}/apply', [MyRecruitmentController::class, 'apply'])->name('job-openings.apply');
Route::get('applications', [MyRecruitmentController::class, 'applications'])->name('applications.index');
Route::get('requisitions', [MyRecruitmentController::class, 'requisitions'])->name('requisitions.index');
Route::post('requisitions', [MyRecruitmentController::class, 'fileRequisition'])->name('requisitions.store');
Route::get('interviews', [MyRecruitmentController::class, 'interviews'])->name('interviews.index');
Route::put('interviews/{interview}/scorecard', [MyRecruitmentController::class, 'score'])->name('interviews.scorecard');
