<?php

namespace App\Domain\Providers\Requests;

use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Requests\Handlers\CompanyAdminHandler;
use App\Domain\Providers\Requests\Handlers\CompanyMemberHandler;
use App\Domain\Providers\Requests\Handlers\PlanAssignmentHandler;
use App\Domain\Providers\Requests\Handlers\PlanDesignHandler;
use App\Domain\Providers\Requests\Handlers\WalletTopUpHandler;

/** One handler per request type — a new type is a class and a line here. */
class ProviderRequestHandlers
{
    /** @var array<string, class-string<ProviderRequestHandler>> */
    private const HANDLERS = [
        'plan_design' => PlanDesignHandler::class,
        'plan_assignment' => PlanAssignmentHandler::class,
        'wallet_top_up' => WalletTopUpHandler::class,
        'company_member' => CompanyMemberHandler::class,
        'company_admin' => CompanyAdminHandler::class,
    ];

    public function for(ProviderRequestType $type): ProviderRequestHandler
    {
        return app(self::HANDLERS[$type->value]);
    }
}
