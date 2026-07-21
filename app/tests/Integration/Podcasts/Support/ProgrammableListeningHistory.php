<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts\Support;

use App\Module\Podcasts\Domain\Port\PodcastListeningHistoryInterface;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use RuntimeException;

/**
 * A listening source whose answer can be changed between sweeps.
 *
 * A PHPUnit stub would not do here: the container refuses to replace a service
 * once it has been initialized, so the double has to be installed once, before
 * the first sweep resolves it, and then reprogrammed in place — which is also a
 * fair model of reality, where it is the same source returning different state
 * on consecutive polls.
 */
final class ProgrammableListeningHistory implements PodcastListeningHistoryInterface
{
    /** @var list<ListenedEpisode> */
    private array $episodes = [];

    private ?RuntimeException $failure = null;

    public int $calls = 0;

    /**
     * @param list<ListenedEpisode> $episodes
     */
    public function willReturn(array $episodes): void
    {
        $this->episodes = $episodes;
        $this->failure = null;
    }

    public function willFailWith(string $message): void
    {
        $this->failure = new RuntimeException($message);
    }

    public function fetchListenedEpisodes(): array
    {
        ++$this->calls;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->episodes;
    }
}
