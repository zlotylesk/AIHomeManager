<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\ReadModel;

/**
 * A "what to watch / read next" suggestion, normalized from the Series
 * (ongoing shows) and Books (currently reading) modules. `kind` is the source
 * ('series' | 'book'); `detail` carries a short secondary line (release year for
 * a show, author for a book).
 */
final readonly class Recommendation
{
    public function __construct(
        public string $kind,
        public string $title,
        public ?string $coverUrl,
        public ?string $detail,
    ) {
    }
}
