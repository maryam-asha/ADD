<?php

namespace Add\Hr\Contracts;

use Add\Hr\Payroll\Models\PayrollRun;

/**
 * Turns an approved run into journal lines for the host's accounting.
 */
interface PayrollAccountingExporter
{
    /**
     * @return list<array<string, string>>
     */
    public function rows(PayrollRun $run): array;
}
