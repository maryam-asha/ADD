<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Performance\Http\Controllers\Admin\GoalController;
use Add\Hr\Performance\Http\Controllers\Admin\ReviewController;
use Add\Hr\Performance\Http\Controllers\Admin\ReviewCycleController;
use Add\Hr\Performance\Http\Controllers\Admin\ReviewTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * Performance: review templates, cycles moved through their phases, every
 * review with its calibration, and goals.
 */

Route::middleware('can:'.Abilities::PERFORMANCE_VIEW)->group(function () {
    Route::get('review-templates', [ReviewTemplateController::class, 'index'])->name('review-templates.index');
    Route::get('review-templates/{template}', [ReviewTemplateController::class, 'show'])->name('review-templates.show');
    Route::get('review-cycles', [ReviewCycleController::class, 'index'])->name('review-cycles.index');
    Route::get('review-cycles/{cycle}', [ReviewCycleController::class, 'show'])->name('review-cycles.show');
    Route::get('review-cycles/{cycle}/reviews', [ReviewCycleController::class, 'reviews'])->name('review-cycles.reviews');
    Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
    Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
});

Route::middleware('can:'.Abilities::PERFORMANCE_MANAGE)->group(function () {
    Route::post('review-templates', [ReviewTemplateController::class, 'store'])->name('review-templates.store');
    Route::patch('review-templates/{template}', [ReviewTemplateController::class, 'update'])->name('review-templates.update');

    Route::post('review-cycles', [ReviewCycleController::class, 'store'])->name('review-cycles.store');
    Route::patch('review-cycles/{cycle}', [ReviewCycleController::class, 'update'])->name('review-cycles.update');
    Route::post('review-cycles/{cycle}/launch', [ReviewCycleController::class, 'launch'])->name('review-cycles.launch');
    Route::post('review-cycles/{cycle}/advance', [ReviewCycleController::class, 'advance'])->name('review-cycles.advance');
    Route::post('review-cycles/{cycle}/reviews', [ReviewCycleController::class, 'addReview'])->name('review-cycles.reviews.store');

    Route::patch('reviews/{review}', [ReviewController::class, 'reassign'])->name('reviews.reassign');
    Route::post('reviews/{review}/calibrate', [ReviewController::class, 'calibrate'])->name('reviews.calibrate');

    Route::post('employees/{employee}/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::patch('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::delete('goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');
});
