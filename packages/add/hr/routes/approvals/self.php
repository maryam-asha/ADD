<?php

use Add\Hr\Approvals\Http\Controllers\SelfService\ApprovalInboxController;
use Add\Hr\Approvals\Http\Controllers\SelfService\DelegationController;
use Illuminate\Support\Facades\Route;

/*
 * The approver's inbox and their delegations. Who may decide what is the
 * engine's call, per request — not an ability on the route.
 */
Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');
Route::get('approvals/{approvalRequest}', [ApprovalInboxController::class, 'show'])->name('approvals.show');
Route::post('approvals/{approvalRequest}/approve', [ApprovalInboxController::class, 'approve'])->name('approvals.approve');
Route::post('approvals/{approvalRequest}/reject', [ApprovalInboxController::class, 'reject'])->name('approvals.reject');

Route::get('delegations', [DelegationController::class, 'index'])->name('delegations.index');
Route::post('delegations', [DelegationController::class, 'store'])->name('delegations.store');
Route::delete('delegations/{delegation}', [DelegationController::class, 'destroy'])->name('delegations.destroy');
