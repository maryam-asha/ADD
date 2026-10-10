<?php

namespace Tests\Feature\Leasing;

use Tests\Concerns\BuildsOfficeLeases;
use Tests\Feature\Invoicing\InvoicingTestCase;

/** Roles, permissions, currencies and settings (InvoicingTestCase), plus offices and a fake TTLock. */
abstract class LeasingTestCase extends InvoicingTestCase
{
    use BuildsOfficeLeases;
}
