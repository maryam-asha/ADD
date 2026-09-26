<?php

namespace App\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Policies\CompanyPolicy;
use App\Domain\Settings\Services\SettingService;
use App\Events\Finance\PaymentAccepted;
use App\Events\Finance\PaymentReversed;
use App\Listeners\EnsureAuthenticatedUserIsActive;
use App\Listeners\Finance\FulfilAcceptedPayment;
use App\Listeners\Finance\ReverseWalletCreditOnPaymentReversed;
use App\Listeners\SetLocaleFromUserPreference;
use App\Support\Presenters\EventPricePresenter;
use App\Support\Time\LocalTime;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Both hold a per-request memo of values that do not change during a
        // request — the configured timezone, the point rate, the base
        // currency. Without singletons each resolution built a fresh
        // instance with an empty memo, and CACHE_STORE is `database` here, so
        // every re-read was a query: one events page read the configured
        // timezone 68 times and the point rate 34 more.
        $this->app->singleton(SettingService::class);
        $this->app->singleton(LocalTime::class);
        // Same reason: it memoises currency rows to format a price, and a
        // listing formats one per card.
        $this->app->singleton(EventPricePresenter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registered FIRST, and the order is load-bearing: corrects the
        // provisional locale SetLocaleFromHeader set before auth resolved, to
        // the user's preferred_language — but only when no valid `lang` header
        // was sent. Listeners fire in registration order, and
        // EnsureAuthenticatedUserIsActive below abort()s for a suspended
        // account, which throws — so anything registered after it never runs
        // on exactly the request whose error message needs translating. With
        // the old order, a suspended member whose preferred_language is 'en'
        // and who sent no `lang` header got the suspension message in Arabic
        // (SetLocaleFromHeader's provisional default). Safe to run first: this
        // listener only calls App::setLocale() and never throws.
        Event::listen(TokenAuthenticated::class, SetLocaleFromUserPreference::class);
        Event::listen(Authenticated::class, SetLocaleFromUserPreference::class);

        // Applies to every guard, every route, with no per-route/group
        // registration — see EnsureAuthenticatedUserIsActive's own docblock
        // for why this is an event listener and not a middleware.
        Event::listen(TokenAuthenticated::class, EnsureAuthenticatedUserIsActive::class);
        Event::listen(Authenticated::class, EnsureAuthenticatedUserIsActive::class);

        // Card payments (docs/paymera.md): FulfilAcceptedPayment and
        // ReverseWalletCreditOnPaymentReversed are NOT registered here.
        //
        // Laravel 12 auto-discovers a listener from its handle() parameter
        // type, so an explicit Event::listen() for these would register each
        // one TWICE and run it twice per event. That was not theoretical:
        // it made a single reversal write two -100 clawback rows, because
        // that listener has no idempotence check of its own (the credit
        // listener's hasBeenCredited() guard happened to absorb its own
        // double-fire, which is precisely why the bug hid on that side).
        //
        // The two listeners above ARE still synchronous rather than queued,
        // which is deliberate: each moves money, and a queued failure would
        // leave a payment reading as settled with nothing credited or
        // clawed back.

        // Models live under App\Domain\<Domain>\Policies, not App\Policies, so
        // Laravel's convention-based policy discovery misses these — register
        // explicitly. This is the only Policy in the app (D.8).
        Gate::policy(Company::class, CompanyPolicy::class);

        $this->registerLoginRateLimiter();

        // Models live under App\Domain\<Domain>\Models, not App\Models, so Laravel's
        // default guess (Database\Factories\Domain\<Domain>\Models\XFactory) misses.
        // Factories stay flat in database/factories/ and are matched by class name.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    /**
     * Keyed on phone *and* IP rather than IP alone: members behind one office
     * NAT would otherwise share a budget and lock each other out, while an
     * attacker rotating addresses against a single number would never hit the
     * limit at all. The named limiter exists because `throttle:5,1` can only
     * key on the request's IP or authenticated user — neither of which is the
     * thing being guessed here.
     */
    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('member-login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->input('phone').'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => __('api.auth.too_many_attempts'),
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers)));
    }
}
