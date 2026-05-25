<?php

declare(strict_types=1);

namespace App\Controller;

use App\Csv\CsvBuilder;
use App\Module\Tasks\Application\Command\CancelTask;
use App\Module\Tasks\Application\Command\CompleteTask;
use App\Module\Tasks\Application\Command\CreateTask;
use App\Module\Tasks\Application\Command\DeleteTask;
use App\Module\Tasks\Application\Command\UpdateTask;
use App\Module\Tasks\Application\DTO\TaskDTO;
use App\Module\Tasks\Application\DTO\TaskTimeDTO;
use App\Module\Tasks\Application\DTO\TimeReportDTO;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Application\Query\GetAllTasks;
use App\Module\Tasks\Application\Query\GetTaskById;
use App\Module\Tasks\Application\Query\GetTimeReport;
use App\Module\Tasks\Application\Service\TaskCsvExporter;
use DateTimeImmutable;
use DomainException;
use Exception;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tasks')]
final class TasksController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Target('query.bus')]
        private readonly MessageBusInterface $queryBus,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusParam = $request->query->get('status');

        if (null !== $statusParam && !in_array($statusParam, ['pending', 'completed', 'cancelled'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid status. Allowed: pending, completed, cancelled.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var TaskDTO[] $tasks */
        $tasks = $this->queryBus->dispatch(new GetAllTasks($statusParam))->last(HandledStamp::class)->getResult();

        return new JsonResponse(array_map($this->serializeDTO(...), $tasks));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function detail(string $id): JsonResponse
    {
        /** @var TaskDTO|null $dto */
        $dto = $this->queryBus->dispatch(new GetTaskById($id))->last(HandledStamp::class)->getResult();

        if (null === $dto) {
            return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        foreach (['title', 'start', 'end'] as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(
                    ['error' => sprintf('Field "%s" is required.', $field)],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        try {
            $id = $this->commandBus->dispatch(new CreateTask(
                title: trim((string) $data['title']),
                start: trim((string) $data['start']),
                end: trim((string) $data['end']),
            ))->last(HandledStamp::class)->getResult();
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $this->commandBus->dispatch(new UpdateTask(
                id: $id,
                title: isset($data['title']) ? trim((string) $data['title']) : null,
                start: isset($data['start']) ? trim((string) $data['start']) : null,
                end: isset($data['end']) ? trim((string) $data['end']) : null,
            ));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof TaskNotFoundException) {
                return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($prev instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/complete', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function complete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new CompleteTask($id));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof TaskNotFoundException) {
                return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($prev instanceof DomainException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/cancel', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function cancel(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new CancelTask($id));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof TaskNotFoundException) {
                return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($prev instanceof DomainException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteTask($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof TaskNotFoundException) {
                return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/export', methods: ['GET'])]
    public function export(Request $request, TaskCsvExporter $exporter): Response
    {
        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        try {
            $from = null !== $fromStr ? new DateTimeImmutable($fromStr) : null;
            $to = null !== $toStr ? new DateTimeImmutable($toStr) : null;
        } catch (Exception) {
            return new JsonResponse(
                ['error' => 'Invalid date format. Use YYYY-MM-DD.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new Response(
            CsvBuilder::build(TaskCsvExporter::HEADERS, $exporter->rows($from, $to)),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename=tasks.csv',
            ],
        );
    }

    #[Route('/time-report', methods: ['GET'])]
    public function timeReport(Request $request): JsonResponse
    {
        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if (null === $fromStr || null === $toStr) {
            return new JsonResponse(
                ['error' => 'Parameters from and to are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $from = new DateTimeImmutable($fromStr);
            $to = new DateTimeImmutable($toStr);
        } catch (Exception) {
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
                fn (TaskTimeDTO $t) => [
                    'taskId' => $t->taskId,
                    'title' => $t->title,
                    'minutes' => $t->minutes,
                ],
                $report->breakdown
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeDTO(TaskDTO $dto): array
    {
        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'start' => $dto->start,
            'end' => $dto->end,
            'durationMinutes' => $dto->durationMinutes,
            'status' => $dto->status,
            'googleEventId' => $dto->googleEventId,
        ];
    }
}
