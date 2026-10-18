<?php

use Add\Hr\Payroll\Http\Controllers\SelfService\MyPayController;
use Illuminate\Support\Facades\Route;

/*
 * My payslips, my loans, my certificates.
 */
Route::get('payslips', [MyPayController::class, 'payslips'])->name('payslips.index');
Route::get('payslips/{payslip}', [MyPayController::class, 'payslip'])->name('payslips.show');
Route::get('loans', [MyPayController::class, 'loans'])->name('loans.index');
Route::post('loans', [MyPayController::class, 'requestLoan'])->name('loans.store');
Route::post('loans/{loan}/cancel', [MyPayController::class, 'cancelLoan'])->name('loans.cancel');
Route::get('certificates/employment', [MyPayController::class, 'employmentCertificate'])->name('certificates.employment');
Route::get('certificates/salary', [MyPayController::class, 'salaryCertificate'])->name('certificates.salary');
