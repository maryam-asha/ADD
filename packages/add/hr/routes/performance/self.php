<?php

use Add\Hr\Performance\Http\Controllers\SelfService\MyPerformanceController;
use Illuminate\Support\Facades\Route;

/*
 * My goals and reviews; as a manager, my direct reports' goals and the
 * reviews I conduct. Who may do what is checked per record
 * (GoalService::canManage, ReviewService).
 */
Route::get('goals', [MyPerformanceController::class, 'goals'])->name('goals.index');
Route::post('goals', [MyPerformanceController::class, 'storeGoal'])->name('goals.store');
Route::patch('goals/{goal}', [MyPerformanceController::class, 'updateGoal'])->name('goals.update');

Route::get('reviews', [MyPerformanceController::class, 'reviews'])->name('reviews.index');
Route::get('reviews/{review}', [MyPerformanceController::class, 'review'])->name('reviews.show');
Route::put('reviews/{review}/self', [MyPerformanceController::class, 'submitSelf'])->name('reviews.self');

Route::get('team-reviews', [MyPerformanceController::class, 'teamReviews'])->name('team-reviews.index');
Route::get('team-reviews/{review}', [MyPerformanceController::class, 'teamReview'])->name('team-reviews.show');
Route::put('team-reviews/{review}', [MyPerformanceController::class, 'submitManager'])->name('team-reviews.submit');
