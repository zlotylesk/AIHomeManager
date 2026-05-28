<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Enum;

enum ListeningSource: string
{
    case LASTFM_SCROBBLE = 'lastfm_scrobble';
    case LASTFM_TOP_DELTA = 'lastfm_top_delta';
    case MANUAL = 'manual';
}
