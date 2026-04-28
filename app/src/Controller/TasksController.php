<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Tasks\Application\DTO\TaskTimeDTO;
use App\Module\Tasks\Application\DTO\TimeReportDTO;
use App\Module\Tasks\Application\Query\GetTimeReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tasks')]
final class TasksController extends AbstractController
{
    public function __construct(
        #[Target('query.bus')] private readonly MessageBusInterface $queryBus,
    ) {}

    #[Route('/time-report', methods: ['GET'])]
    public function timeReport(Request $request): JsonResponse
    {
        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if ($fromStr === null || $toStr === null) {
            return new JsonResponse(
                ['error' => 'Parameters from and to are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
            $to = new \DateTimeImmutable($toStr);
        } catch (\Exception) {
            return new JsonResponse(
                ['error' => 'Invalid date format. Use YYYY-MM-DD.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var TimeReportDTO $report */
        $report = $this->queryBus->dispatch(new GetTimeReport($from, $to))->last(HandledStamp::class)->getResult();

        return new JsonResponse([
            'totalMinutes' => $report->totalMinutes,
            'totalHours' => $report->totalHours,
            'breakdown' => array_map(
                fn(TaskTimeDTO $t) => [
                    'taskId' => $t->taskId,
                    'title' => $t->title,
                    'minutes' => $t->minutes,
                ],
                $report->breakdown
            ),
        ]);
    }
}
