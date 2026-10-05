<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * A price is composed in exactly one place: PriceQuoteService.
 *
 * Before it, the list price (AmountCalculator) and the plan discount
 * (PlanDiscountService) were stitched together by hand in six places —
 * creation, batch, extension, preview, closure and plan purchase. A sale
 * and a coupon on top would have been a seventh copy of "which discount
 * wins", and the first two copies to drift would quote a member one price
 * and charge them another. So the parts a price is made of may only be
 * called from the Pricing domain, and the money arithmetic there is integer
 * bcmath with one documented rounding each way, never a float helper.
 *
 * See docs/decisions/pricing-offers-and-coupons.md.
 */
class PricingIsCentralizedTest extends TestCase
{
    use ScansSourceFiles;

    /**
     * Pricing time outside the Pricing domain. The three fallbacks price a
     * record from before its price was stored — they read what such a
     * record cost, and a sale or coupon could not have been on it.
     *
     * @var list<string>
     */
    private const TIME_PRICING_ALLOWED = [
        'app/Domain/Booking/Services/AmountCalculator.php',
        // Refunds a booking paid before payment rows existed.
        'app/Domain/Booking/Services/BookingRefunder.php',
        // Prices a booking that has no price_points yet (created before the column).
        'app/Domain/Finance/Payments/Fulfillers/BookingFulfiller.php',
        // Reports a session closed before price_points existed.
        'app/Domain/Booking/Services/SessionReportBuilder.php',
    ];

    /** @var list<string> */
    private const PLAN_DISCOUNT_ALLOWED = [
        'app/Domain/Membership/Services/PlanDiscountService.php',
    ];

    public function test_time_is_priced_only_through_the_pricing_domain(): void
    {
        $this->assertNoneOutside(
            '/->for(Range|Segments)\(/',
            self::TIME_PRICING_ALLOWED,
            'Price time through PriceQuoteService (forBooking, forExtension, forSession), not AmountCalculator directly:',
        );
    }

    public function test_a_plan_discount_is_chosen_and_applied_only_while_composing_a_quote(): void
    {
        $this->assertNoneOutside(
            '/[dD]iscounts->(apply|resolveFor)\(/',
            self::PLAN_DISCOUNT_ALLOWED,
            'Which discount wins is PriceQuoteService\'s call — a plan discount, a sale and a coupon are weighed together there:',
        );
    }

    public function test_a_coupon_discount_is_computed_only_in_the_pricing_domain(): void
    {
        $this->assertNoneOutside(
            '/->discountFor\(/',
            [],
            'A coupon is applied through PriceQuoteService, which knows what it stacks on:',
        );
    }

    /**
     * Times round up once (AmountCalculator), discounts round down
     * (bcmath truncation) — and nothing in between is a float.
     */
    public function test_pricing_arithmetic_is_integer_bcmath(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path('Domain/Pricing')) as $path => $contents) {
            if (preg_match_all('/\b(ceil|floor|round|floatval|fdiv)\s*\(|\(float\)/', $contents, $matches)) {
                $violations[] = "{$path}: ".implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['Pricing works in integer points with bcmath; a float rounding helper here breaks the one-rounding-each-way rule:'],
            $violations,
        )));
    }

    /**
     * An activation that charges debits the plan's list price — missing a
     * live sale and any coupon. A plan is paid for through PaymentService,
     * whose fulfiller activates with `charge: false`; staff activations are
     * free. So every call says `charge: false` out loud rather than
     * inheriting the default.
     */
    public function test_no_membership_activation_charges_the_list_price(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if ($path === 'app/Domain/Membership/Services/MembershipActivationService.php'
                || ! str_contains($contents, 'MembershipActivationService')) {
                continue;
            }

            foreach ($this->callsTo('activate', $contents) as $arguments) {
                if (! preg_match('/\bcharge:\s*false\b/', $arguments)) {
                    $violations[] = "{$path}: activate({$this->squash($arguments)})";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['A membership activation must pass charge: false — the price is charged through PaymentService and PriceQuoteService:'],
            $violations,
        )));
    }

    /**
     * @param  list<string>  $allowed  besides everything under app/Domain/Pricing
     */
    private function assertNoneOutside(string $pattern, array $allowed, string $message): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if (str_starts_with($path, 'app/Domain/Pricing/') || in_array($path, $allowed, true)) {
                continue;
            }

            if (preg_match_all($pattern, $contents, $matches)) {
                $violations[] = "{$path}: ".implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge([$message], $violations)));
    }

    /**
     * The argument text of every `->method(...)` call, parentheses balanced.
     *
     * @return list<string>
     */
    private function callsTo(string $method, string $contents): array
    {
        $calls = [];
        $offset = 0;

        while (($start = strpos($contents, "->{$method}(", $offset)) !== false) {
            $open = $start + strlen("->{$method}(");
            $depth = 1;

            for ($i = $open; $i < strlen($contents) && $depth > 0; $i++) {
                $depth += match ($contents[$i]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
            }

            $calls[] = substr($contents, $open, $i - $open - 1);
            $offset = $i;
        }

        return $calls;
    }

    private function squash(string $code): string
    {
        return trim(preg_replace('/\s+/', ' ', $code));
    }
}
