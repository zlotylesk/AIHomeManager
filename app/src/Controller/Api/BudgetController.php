<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Budget\BudgetRequestParser;
use App\Csv\CsvBuilder;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Budget\Application\Command\AddTransaction;
use App\Module\Budget\Application\Command\CreateCategory;
use App\Module\Budget\Application\Command\DeleteCategory;
use App\Module\Budget\Application\Command\DeleteTransaction;
use App\Module\Budget\Application\Command\RenameCategory;
use App\Module\Budget\Application\Command\SetMonthlyLimit;
use App\Module\Budget\Application\Command\UpdateTransaction;
use App\Module\Budget\Application\DTO\CategoryDTO;
use App\Module\Budget\Application\DTO\MonthlyBudgetReportDTO;
use App\Module\Budget\Application\DTO\TransactionDTO;
use App\Module\Budget\Application\Exception\CategoryHasTransactionsException;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Application\Query\GetCategories;
use App\Module\Budget\Application\Query\GetMonthlyBudgetReport;
use App\Module\Budget\Application\Query\GetTransactions;
use App\Module\Budget\Application\Service\BudgetCsvExporter;
use App\Pdf\PdfBuilder;
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

/**
 * Thin REST surface for the Budget module: reads via query.bus, writes via
 * command.bus, no domain logic (payload parsing lives in
 * BudgetRequestParser). Version-agnostic path — served under /api/v1/budget
 * and the /api/budget alias (ADR-008).
 */
#[Route('/budget')]
final class BudgetController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly BudgetRequestParser $parser,
    ) {
    }

    #[Route('/transactions', methods: ['GET'])]
    #[OA\Get(
        summary: 'List ledger transactions',
        description: 'Every recorded transaction, newest first. The three filters are independent and combine with AND. Amounts are whole minor units (grosze) — never a decimal — so a month of summed transactions cannot drift on float rounding.',
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'month', in: 'query', required: false, description: 'Calendar month to narrow to, `YYYY-MM`. A month that does not exist (e.g. `2026-13`) is rejected rather than rolled over.', schema: new OA\Schema(type: 'string', example: '2026-07')),
            new OA\Parameter(name: 'categoryId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The matching transactions, newest first.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: TransactionDTO::class))),
            ),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function listTransactions(Request $request): JsonResponse
    {
        try {
            /** @var TransactionDTO[] $transactions */
            $transactions = $this->queryBus->ask(new GetTransactions(
                month: $this->parser->optionalMonth($request),
                categoryId: $this->parser->optionalCategoryId($request),
                type: $this->parser->optionalType($request),
            ));
        } catch (HandlerFailedException $e) {
            return $this->mapReadFailure($e);
        }

        return new JsonResponse($this->normalizer->normalize($transactions));
    }

    #[Route('/transactions', methods: ['POST'])]
    #[OA\Post(
        summary: 'Record a transaction',
        description: "Books an amount against a category. The transaction's `type` must match the category's own type — a category cannot mix income and expense, and a mismatch is a 422 rather than a silently reclassified row.",
        tags: ['Budget'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amountInCents', 'date', 'categoryId', 'type'],
                properties: [
                    new OA\Property(property: 'amountInCents', type: 'integer', description: 'Whole minor units, strictly greater than zero.', example: 4999),
                    new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217 code. Defaults to PLN when omitted.', example: 'PLN'),
                    new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Strict `YYYY-MM-DD`. An impossible day (e.g. `2026-02-31`) is rejected, never rolled forward into the next month.', example: '2026-07-15'),
                    new OA\Property(property: 'categoryId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'description', type: ['string', 'null']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function addTransaction(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new AddTransaction(
            amountInCents: $this->parser->requireAmountInCents($data),
            currency: $this->parser->currency($data),
            date: $this->parser->requireDate($data),
            categoryId: $this->parser->requireCategoryId($data),
            type: $this->parser->requireType($data),
            description: $this->parser->optionalDescription($data),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/transactions/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Replace a transaction',
        description: 'A full replace, not a partial merge: every field is required and an omitted one is not preserved. The same type-must-match-the-category rule as create applies.',
        tags: ['Budget'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amountInCents', 'date', 'categoryId', 'type'],
                properties: [
                    new OA\Property(property: 'amountInCents', type: 'integer', example: 6000),
                    new OA\Property(property: 'currency', type: 'string', example: 'PLN'),
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-07-20'),
                    new OA\Property(property: 'categoryId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'description', type: ['string', 'null']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Updated.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function updateTransaction(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new UpdateTransaction(
            id: $id,
            amountInCents: $this->parser->requireAmountInCents($data),
            currency: $this->parser->currency($data),
            date: $this->parser->requireDate($data),
            categoryId: $this->parser->requireCategoryId($data),
            type: $this->parser->requireType($data),
            description: $this->parser->optionalDescription($data),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/transactions/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Delete a transaction',
        description: 'Deleting an already-deleted transaction is a 404, not a silent success — a delete here is an explicit user action on their own ledger, not an operation that can legitimately double-fire.',
        tags: ['Budget'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function deleteTransaction(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteTransaction($id));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories', methods: ['GET'])]
    #[OA\Get(
        summary: 'List budget categories',
        description: 'Every category with its type and optional monthly limit. A category with no limit reports `monthlyLimitInCents: null` — "no limit" is a distinct state from a limit of zero.',
        tags: ['Budget'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The categories.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: CategoryDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function listCategories(): JsonResponse
    {
        /** @var CategoryDTO[] $categories */
        $categories = $this->queryBus->ask(new GetCategories());

        return new JsonResponse($this->normalizer->normalize($categories));
    }

    #[Route('/categories', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a budget category',
        description: 'The name must be unique **within its type**, not globally — an "Ubezpieczenie" expense and an "Ubezpieczenie" income category track different money flows and may coexist. A collision within one type is a 409. The type is immutable once created; no endpoint changes it.',
        tags: ['Budget'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Zakupy spożywcze'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function createCategory(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new CreateCategory(
            name: $this->parser->requireName($data),
            type: $this->parser->requireType($data),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/categories/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Rename a category',
        description: 'Only the name changes — the type is immutable and the monthly limit has its own endpoint. Renaming a category to the name it already has is a no-op success, not a false conflict.',
        tags: ['Budget'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [new OA\Property(property: 'name', type: 'string', example: 'Rachunki domowe')],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Renamed.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function renameCategory(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $name = $this->parser->requireName($data);

        try {
            $this->commandBus->dispatch(new RenameCategory($id, $name));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Delete a category',
        description: 'Refused with a 409 while any transaction is still filed under it. The alternative — cascading — would silently destroy ledger history, which is the one thing a personal finance app cannot afford to lose quietly; the category must be emptied first.',
        tags: ['Budget'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function deleteCategory(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteCategory($id));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories/{id}/limit', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: "Set or clear a category's monthly limit",
        description: 'Both halves of the amount must be supplied together, or both omitted/null to clear the limit. A half-stated range is a 422 rather than being silently persisted as "no limit".',
        tags: ['Budget'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'amountInCents', type: ['integer', 'null'], description: 'Whole minor units. Null (with a null currency) clears the limit.', example: 100000),
                    new OA\Property(property: 'currency', type: ['string', 'null'], example: 'PLN'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Limit set or cleared.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function setMonthlyLimit(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        [$amountInCents, $currency] = $this->parser->parseMonthlyLimit($data);

        try {
            $this->commandBus->dispatch(new SetMonthlyLimit($id, $amountInCents, $currency));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/report', methods: ['GET'])]
    #[OA\Get(
        summary: 'Monthly income/expense report',
        description: "The month's totals and balance, plus a spend-vs-limit row for every category — including ones nothing was spent against, which appear with a zero rather than dropping out of the report. `percentUsed` is null for a category with no limit (nothing to exceed), and `overLimit` is strictly greater-than: a category spent exactly to its limit is at 100% but not over it.",
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'month', in: 'query', required: true, description: 'The month to report on, `YYYY-MM`. A month that does not exist is rejected rather than rolled over into a neighbouring one.', schema: new OA\Schema(type: 'string', example: '2026-07')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The monthly report.',
                content: new OA\JsonContent(ref: new Model(type: MonthlyBudgetReportDTO::class)),
            ),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function report(Request $request): JsonResponse
    {
        $month = $this->parser->requireMonth($request);

        try {
            $report = $this->queryBus->ask(new GetMonthlyBudgetReport($month));
        } catch (HandlerFailedException $e) {
            return $this->mapReadFailure($e);
        }

        return new JsonResponse($this->normalizer->normalize($report));
    }

    /**
     * CSV/PDF export of either the ledger or the monthly report (the
     * Tasks/Articles export pattern). The report half goes through query.bus
     * rather than being re-queried in the exporter, so the exported figures
     * cannot drift from the ones `/budget/report` serves; the transaction half
     * streams from the exporter, since the ledger has no natural size bound.
     */
    #[Route('/export', methods: ['GET'])]
    #[OA\Get(
        summary: 'Export the ledger or the monthly report as CSV/PDF',
        description: 'Two datasets behind one endpoint. The selector is `dataset`, not `type`, because `type` is already spent on income-vs-expense and one endpoint cannot carry two meanings of the same parameter. `dataset=transactions` honours the same optional filters as the list; `dataset=report` requires `month`. Amounts are rendered in decimal units, not the stored minor units — a `499900` column next to `PLN` reads as half a million złoty. The report CSV deliberately carries only the per-category rows (a totals line inside a tabular export breaks whatever the user pivots on it); the PDF, which is read rather than processed, renders the totals as their own block.',
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'dataset', in: 'query', required: false, description: 'Defaults to `transactions`.', schema: new OA\Schema(type: 'string', enum: ['transactions', 'report'])),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Defaults to `csv`.', schema: new OA\Schema(type: 'string', enum: ['csv', 'pdf'])),
            new OA\Parameter(name: 'month', in: 'query', required: false, description: 'Optional for `transactions`, required for `report`.', schema: new OA\Schema(type: 'string', example: '2026-07')),
            new OA\Parameter(name: 'categoryId', in: 'query', required: false, description: 'Transactions only.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Transactions only.', schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The export, as a file attachment.',
                content: [
                    new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string')),
                    new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                ],
            ),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function export(Request $request, BudgetCsvExporter $exporter, PdfBuilder $pdfBuilder): Response
    {
        $dataset = $this->parser->exportDataset($request);
        $format = $this->parser->exportFormat($request);

        return 'report' === $dataset
            ? $this->exportReport($request, $exporter, $pdfBuilder, $format)
            : $this->exportTransactions($request, $exporter, $pdfBuilder, $format);
    }

    private function exportTransactions(Request $request, BudgetCsvExporter $exporter, PdfBuilder $pdfBuilder, string $format): Response
    {
        $month = $this->parser->optionalMonth($request);
        $categoryId = $this->parser->optionalCategoryId($request);
        $type = $this->parser->optionalType($request);

        try {
            if ('csv' === $format) {
                return self::attachment(
                    CsvBuilder::build(BudgetCsvExporter::TRANSACTION_HEADERS, $exporter->transactionRows($month, $categoryId, $type)),
                    'text/csv; charset=UTF-8',
                    'budget-transactions.csv',
                );
            }

            $rows = [];
            foreach ($exporter->transactionRows($month, $categoryId, $type) as $row) {
                $rows[] = array_combine(BudgetCsvExporter::TRANSACTION_HEADERS, $row);
            }
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return self::attachment(
            $pdfBuilder->build('exports/budget_transactions_pdf.html.twig', ['rows' => $rows, 'month' => $month]),
            'application/pdf',
            'budget-transactions.pdf',
        );
    }

    private function exportReport(Request $request, BudgetCsvExporter $exporter, PdfBuilder $pdfBuilder, string $format): Response
    {
        $month = $this->parser->requireMonth($request);

        try {
            /** @var MonthlyBudgetReportDTO $report */
            $report = $this->queryBus->ask(new GetMonthlyBudgetReport($month));
        } catch (HandlerFailedException $e) {
            return $this->mapReadFailure($e);
        }

        if ('csv' === $format) {
            return self::attachment(
                CsvBuilder::build(BudgetCsvExporter::REPORT_HEADERS, $exporter->reportRows($report)),
                'text/csv; charset=UTF-8',
                'budget-report.csv',
            );
        }

        $rows = [];
        foreach ($exporter->reportRows($report) as $row) {
            $rows[] = array_combine(BudgetCsvExporter::REPORT_HEADERS, $row);
        }

        $totals = $exporter->reportTotals($report);

        return self::attachment(
            $pdfBuilder->build('exports/budget_report_pdf.html.twig', [
                'rows' => $rows,
                'month' => $report->month,
                'totalIncome' => $totals['income'],
                'totalExpenses' => $totals['expenses'],
                'balance' => $totals['balance'],
            ]),
            'application/pdf',
            'budget-report.pdf',
        );
    }

    private static function attachment(string $body, string $contentType, string $filename): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename='.$filename,
        ]);
    }

    private function mapWriteFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof TransactionNotFoundException || $previous instanceof CategoryNotFoundException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($previous instanceof CategoryNameAlreadyTakenException || $previous instanceof CategoryHasTransactionsException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }

    private function mapReadFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }
}
