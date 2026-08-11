<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class SetLocaleFromHeaderTest extends TestCase
{
    // Named invokeMiddleware(), not run() as in the task brief's literal
    // listing: PHPUnit 11's TestCase declares a `final public function
    // run()`, so a private method of that same name is a fatal "cannot
    // override final method" error regardless of visibility. Pure rename —
    // behavior and assertions are unchanged.
    private function invokeMiddleware(Request $request): void
    {
        (new SetLocaleFromHeader)->handle($request, fn ($req) => response()->json([]));
    }

    public function test_a_valid_ar_header_sets_the_locale(): void
    {
        $this->invokeMiddleware(Request::create('/', 'GET', server: ['HTTP_LANG' => 'ar']));

        $this->assertSame('ar', App::getLocale());
    }

    public function test_a_valid_en_header_sets_the_locale(): void
    {
        $this->invokeMiddleware(Request::create('/', 'GET', server: ['HTTP_LANG' => 'en']));

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_header_is_case_insensitive(): void
    {
        $this->invokeMiddleware(Request::create('/', 'GET', server: ['HTTP_LANG' => 'EN']));

        $this->assertSame('en', App::getLocale());
    }

    public function test_a_missing_header_falls_back_to_arabic(): void
    {
        $this->invokeMiddleware(Request::create('/', 'GET'));

        $this->assertSame('ar', App::getLocale());
    }

    public function test_an_unsupported_header_value_falls_back_to_arabic(): void
    {
        $this->invokeMiddleware(Request::create('/', 'GET', server: ['HTTP_LANG' => 'fr']));

        $this->assertSame('ar', App::getLocale());
    }
}
