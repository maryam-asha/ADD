<?php

namespace Add\Pm\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A refusal the dashboard can tell apart: besides the translated message it
 * carries a stable `code`, so the UI can react to "project_archived" without
 * matching on prose in either language.
 */
class Refusal
{
    public static function forbidden(string $code): HttpResponseException
    {
        return self::make($code, 403);
    }

    public static function conflict(string $code): HttpResponseException
    {
        return self::make($code, 409);
    }

    private static function make(string $code, int $status): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => __('pm::pm.'.$code), 'code' => $code], $status));
    }
}
