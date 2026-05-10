<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Exception;

use RuntimeException;

/**
 * Base type for all Discogs API failures. Catch this when you only want to know
 * "something went wrong with Discogs" and not why.
 */
class DiscogsApiException extends RuntimeException
{
}
