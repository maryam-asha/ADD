<?php

namespace App\Http\Middleware;

use App\Domain\Providers\Models\Provider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `provider.active`: the account must be linked to a provider, and that
 * provider active. Deactivating a provider cuts every one of its accounts
 * off here, without touching their roles (docs/decisions/service-providers.md).
 * Puts the provider on the request for the controllers.
 */
class EnsureActiveProvider
{
    public const ATTRIBUTE = 'provider';

    public function handle(Request $request, Closure $next): Response
    {
        $provider = Provider::forUser($request->user());

        if ($provider === null || ! $provider->isActive()) {
            return response()->json(['message' => __('api.provider.inactive')], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $provider);

        return $next($request);
    }
}
