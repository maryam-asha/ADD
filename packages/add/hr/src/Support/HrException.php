<?php

namespace Add\Hr\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A business rule said no. An HttpException on purpose: any Laravel host
 * renders it as `{"message": ...}` with its status, without registering a
 * renderer for the package — ADD's catch-all passes HttpExceptionInterface
 * through untouched.
 *
 * The key is a full translation key (`hr::core.position_full`, or a host's
 * own `api.hr.*` from an adapter), translated at the moment of throwing.
 */
class HrException extends HttpException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function fail(string $key, array $params = [], int $status = 422): never
    {
        throw new self($status, __($key, $params));
    }
}
