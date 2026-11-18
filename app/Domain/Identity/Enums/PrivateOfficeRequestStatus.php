<?php

namespace App\Domain\Identity\Enums;

/**
 * The private-office pipeline: request -> quote -> signed contract (PRD
 * §5.3). `Contracted` is reachable only through company creation
 * (Api\V1\Admin\CompanyController::store) — never set directly by a
 * generic update.
 */
enum PrivateOfficeRequestStatus: string
{
    case Requested = 'requested';
    case Quoted = 'quoted';
    case Contracted = 'contracted';
}
