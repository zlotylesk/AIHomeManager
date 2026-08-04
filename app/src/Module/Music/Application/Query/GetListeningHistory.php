<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Query;

use App\Module\Music\Domain\Enum\ListeningSource;
use App\Shared\Pagination\PageRequest;
use DateTimeImmutable;

/**
 * Read the listening history, newest first, one page at a time.
 *
 * The bespoke `limit` this query used to carry was folded into the shared
 * {@see PageRequest} so the API speaks one pagination vocabulary.
 */
final readonly class GetListeningHistory
{
    public function __construct(
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?ListeningSource $source = null,
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
