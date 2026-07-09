<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Csv\CsvBuilder;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
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
use App\Pdf\PdfBuilder;
use DateTimeImmutable;
use DomainException;
use Exception;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/tasks')]
final class TasksController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List tasks',
        description: 'Returns all tasks, optionally filtered by status.',
        tags: ['Tasks'],
        parameters: [
            new OA\QueryParameter(
                name: 'status',
                description: 'Filter by task status. Omit to return every task.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['pending', 'completed', 'cancelled']),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The list of tasks.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: new Model(type: TaskDTO::class)),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
        $tasks = $this->queryBus->ask(new GetAllTasks($statusParam));

        return new JsonResponse($this->normalizer->normalize($tasks));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Get(
        summary: 'Get a task by id',
        tags: ['Tasks'],
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Task UUID.',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The task.',
                content: new OA\JsonContent(ref: new Model(type: TaskDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var TaskDTO|null $dto */
        $dto = $this->queryBus->ask(new GetTaskById($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Task not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a task',
        tags: ['Tasks'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'start', 'end'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Grocery shopping'),
                    new OA\Property(property: 'start', type: 'string', format: 'date-time', example: '2026-07-06T10:00:00'),
                    new OA\Property(property: 'end', type: 'string', format: 'date-time', example: '2026-07-06T11:00:00'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Task created.',
                content: new OA\JsonContent(
                    required: ['id'],
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
            $id = $this->commandBus->dispatchAndReturn(new CreateTask(
                title: trim((string) $data['title']),
                start: trim((string) $data['start']),
                end: trim((string) $data['end']),
            ));
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
    #[OA\Patch(
        summary: 'Update a task (partial)',
        description: 'Partial update — only the provided fields are changed.',
        tags: ['Tasks'],
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Task UUID.',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Grocery shopping'),
                    new OA\Property(property: 'start', type: 'string', format: 'date-time', example: '2026-07-06T10:00:00'),
                    new OA\Property(property: 'end', type: 'string', format: 'date-time', example: '2026-07-06T11:00:00'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Task updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Mark a task completed',
        tags: ['Tasks'],
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Task UUID.',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Task marked completed.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Cancel a task',
        tags: ['Tasks'],
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Task UUID.',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Task cancelled.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Delete(
        summary: 'Delete a task',
        tags: ['Tasks'],
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Task UUID.',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Task deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
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
    #[OA\Get(
        summary: 'Export completed tasks (CSV or PDF)',
        description: 'Streams completed tasks within an optional [from, to] window as a CSV or PDF attachment. The date filter matches when the work happened (time_start).',
        tags: ['Tasks'],
        parameters: [
            new OA\QueryParameter(
                name: 'from',
                description: 'Inclusive lower bound on the task start (YYYY-MM-DD).',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\QueryParameter(
                name: 'to',
                description: 'Inclusive upper bound on the task start (YYYY-MM-DD).',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\QueryParameter(
                name: 'format',
                description: 'Export format.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['csv', 'pdf'], default: 'csv'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The export file as an attachment.',
                content: [
                    new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary')),
                    new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                ],
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function export(Request $request, TaskCsvExporter $csvExporter, PdfBuilder $pdfBuilder): Response
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

        $format = $request->query->get('format', 'csv');
        if (!\in_array($format, ['csv', 'pdf'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid format. Allowed: csv, pdf.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ('csv' === $format) {
            return new Response(
                CsvBuilder::build(TaskCsvExporter::HEADERS, $csvExporter->rows($from, $to)),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename=tasks.csv',
                ],
            );
        }

        $rows = [];
        foreach ($csvExporter->rows($from, $to) as $row) {
            $rows[] = array_combine(TaskCsvExporter::HEADERS, $row);
        }

        return new Response(
            $pdfBuilder->build('exports/tasks_pdf.html.twig', ['rows' => $rows]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=tasks.pdf',
            ],
        );
    }

    #[Route('/time-report', methods: ['GET'])]
    #[OA\Get(
        summary: 'Time report',
        description: 'Aggregates minutes spent on completed tasks within [from, to], with a per-task breakdown.',
        tags: ['Tasks'],
        parameters: [
            new OA\QueryParameter(
                name: 'from',
                description: 'Inclusive lower bound on the task start (YYYY-MM-DD).',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\QueryParameter(
                name: 'to',
                description: 'Inclusive upper bound on the task start (YYYY-MM-DD).',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The aggregated time report.',
                content: new OA\JsonContent(ref: new Model(type: TimeReportDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
        $report = $this->queryBus->ask(new GetTimeReport($from, $to));

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
}
