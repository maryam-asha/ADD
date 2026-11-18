<?php

namespace App\Domain\Finance\Exceptions;

use InvalidArgumentException;

/**
 * An amount of money that is not a plain decimal (`12` or `12.50`): an
 * exponent (`1e3`), a sign, padding, a bare point. bcmath refuses all of
 * them with a ValueError, so ReceivedMoneyConverter refuses them first, as
 * a domain error the HTTP layer answers with 422
 * (`api.payment.invalid_amount`, bootstrap/app.php) — never a 500.
 *
 * The request rules on every payment endpoint already accept only a plain
 * decimal; this is what still holds for a caller that skipped them.
 */
class InvalidMoneyAmountException extends InvalidArgumentException
{
    public static function for(string $amount): self
    {
        return new self("Not a plain decimal amount of money: [{$amount}].");
    }
}
