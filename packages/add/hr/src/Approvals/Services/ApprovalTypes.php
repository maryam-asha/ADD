<?php

namespace Add\Hr\Approvals\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Support\HrException;

/**
 * The request types the engine knows, each with its handler. Modules
 * register theirs from the service provider.
 */
class ApprovalTypes
{
    /** @var array<string, class-string<ApprovalHandler>> */
    private array $handlers = [];

    /**
     * @param  class-string<ApprovalHandler>  $handler
     */
    public function register(string $type, string $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function handler(string $type): ApprovalHandler
    {
        if (! $this->has($type)) {
            HrException::fail('hr::approvals.unknown_type', ['type' => $type]);
        }

        return app($this->handlers[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
