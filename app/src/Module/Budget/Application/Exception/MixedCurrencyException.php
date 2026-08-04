<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Exception;

use RuntimeException;

/**
 * The month being reported holds amounts in more than one currency, or in a
 * currency the budget is not kept in.
 *
 * Deliberately a RuntimeException rather than a DomainException, so it surfaces
 * as a 500 and not as a 4xx: nothing the caller of `GET /budget/report` did
 * caused it. Since {@see \App\Module\Budget\Application\SystemCurrency} rejects
 * a foreign amount on the way in, the only way to reach this state is a write
 * that bypassed the API, and the operator wants to hear about that in the log.
 *
 * Failing is the point. The report's whole job is to state amounts, and there
 * is no honest single number for a month that mixes units — summing them anyway
 * is what produced an unlabelled `20000` from 100 EUR and 100 PLN, and a
 * category limited to 500 PLN reporting 80% used against 400 EUR of spending.
 */
final class MixedCurrencyException extends RuntimeException
{
}
