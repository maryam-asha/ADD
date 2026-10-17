<?php

use Add\Hr\Approvals\Http\Controllers\Admin\ApprovalFlowController;
use Add\Hr\Approvals\Http\Controllers\Admin\ApprovalRequestController;
use Add\Hr\Authorization\Abilities;
use Illuminate\Support\Facades\Route;

/*
 * Approval flows and every open request. Deciding here is an override for
 * an approver who is away; the approver's own path is hr/me/approvals.
 */
Route::middleware('can:'.Abilities::APPROVALS_MANAGE)->group(function () {
    Route::get('approval-types', [ApprovalFlowController::class, 'types'])->name('approval-types.index');
    Route::apiResource('approval-flows', ApprovalFlowController::class)->parameters(['approval-flows' => 'approvalFlow']);

    Route::get('approval-requests', [ApprovalRequestController::class, 'index'])->name('approval-requests.index');
    Route::get('approval-requests/{approvalRequest}', [ApprovalRequestController::class, 'show'])->name('approval-requests.show');
    Route::post('approval-requests/{approvalRequest}/approve', [ApprovalRequestController::class, 'approve'])->name('approval-requests.approve');
    Route::post('approval-requests/{approvalRequest}/reject', [ApprovalRequestController::class, 'reject'])->name('approval-requests.reject');
});
