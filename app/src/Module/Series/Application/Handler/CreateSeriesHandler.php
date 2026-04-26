<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\CreateSeries;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateSeriesHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
    ) {}

    public function __invoke(CreateSeries $command): string
    {
        $id = Uuid::v4()->toRfc4122();

        $this->repository->save(new Series(id: $id, title: $command->title));

        return $id;
    }
}