<?php

declare(strict_types=1);

namespace App\Controller\Budget;

use App\Module\Budget\Application\SystemCurrency;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Payload parsing + shape validation for the Budget REST surface, kept out of
 * the thin BudgetController (the SeriesRequestParser precedent, HMAI-239).
 * Unlike its siblings it is not stateless: it holds the budget's configured
 * currency, because that is what an omitted `currency` field means.
 * On invalid input it throws UnprocessableEntityHttpException,
 * which ApiExceptionListener turns into the {"error": …} 422 the API
 * contract expects. Domain-level validation (amount > 0, valid type/date
 * shape, category existence) stays in the Application handlers — this parser
 * only extracts raw values and enforces request-shape rules.
 */
final readonly class BudgetRequestParser
{
    public function __construct(private SystemCurrency $currency)
    {
    }

    /** @return array<string, mixed> */
    public function decode(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    public function requireAmountInCents(array $data): int
    {
        $value = $data['amountInCents'] ?? null;

        if (!\is_int($value)) {
            throw new UnprocessableEntityHttpException('Field "amountInCents" is required and must be an integer.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function currency(array $data): string
    {
        // The omitted-currency default has to be the *configured* currency, not
        // a literal: with a hardcoded 'PLN' here, an operator who set
        // BUDGET_CURRENCY to anything else would have every request that leaves
        // `currency` out rejected by the very rule that is supposed to protect
        // them, and the module would be unusable in its own configuration.
        $value = $data['currency'] ?? $this->currency->code();

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException('Field "currency" must be a non-empty string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function requireDate(array $data): string
    {
        $value = $data['date'] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException('Field "date" is required (YYYY-MM-DD).');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function requireCategoryId(array $data): string
    {
        $value = $data['categoryId'] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException('Field "categoryId" is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function requireType(array $data): string
    {
        $value = $data['type'] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException('Field "type" is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function optionalDescription(array $data): ?string
    {
        $value = $data['description'] ?? null;

        if (null !== $value && !\is_string($value)) {
            throw new UnprocessableEntityHttpException('Field "description" must be a string or null.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function requireName(array $data): string
    {
        $value = $data['name'] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException('Field "name" is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: ?int, 1: ?string}
     */
    public function parseMonthlyLimit(array $data): array
    {
        $hasAmount = \array_key_exists('amountInCents', $data) && null !== $data['amountInCents'];
        $hasCurrency = \array_key_exists('currency', $data) && null !== $data['currency'];

        if (!$hasAmount && !$hasCurrency) {
            return [null, null];
        }

        if (!$hasAmount || !$hasCurrency) {
            throw new UnprocessableEntityHttpException('Fields "amountInCents" and "currency" must be both set or both null.');
        }

        if (!\is_int($data['amountInCents']) || !\is_string($data['currency'])) {
            throw new UnprocessableEntityHttpException('Field "amountInCents" must be an integer and "currency" a string.');
        }

        return [$data['amountInCents'], $data['currency']];
    }

    public function requireMonth(Request $request): string
    {
        $month = $request->query->get('month');

        if (!\is_string($month) || '' === trim($month)) {
            throw new UnprocessableEntityHttpException('Query parameter "month" is required (YYYY-MM).');
        }

        return $month;
    }

    public function optionalMonth(Request $request): ?string
    {
        $month = $request->query->get('month');

        return \is_string($month) && '' !== trim($month) ? $month : null;
    }

    public function optionalCategoryId(Request $request): ?string
    {
        $categoryId = $request->query->get('categoryId');

        return \is_string($categoryId) && '' !== trim($categoryId) ? $categoryId : null;
    }

    public function optionalType(Request $request): ?string
    {
        $type = $request->query->get('type');

        return \is_string($type) && '' !== trim($type) ? $type : null;
    }

    /**
     * Which of the two things the export endpoint can produce. Named `dataset`
     * rather than the `type` the sibling endpoints use, because the ledger
     * already spends `type` on income-vs-expense and one endpoint cannot carry
     * two unrelated meanings of the same parameter.
     */
    public function exportDataset(Request $request): string
    {
        $dataset = $request->query->get('dataset', 'transactions');

        if (!\in_array($dataset, ['transactions', 'report'], true)) {
            throw new UnprocessableEntityHttpException('Invalid dataset. Allowed: transactions, report.');
        }

        return $dataset;
    }

    public function exportFormat(Request $request): string
    {
        $format = $request->query->get('format', 'csv');

        if (!\in_array($format, ['csv', 'pdf'], true)) {
            throw new UnprocessableEntityHttpException('Invalid format. Allowed: csv, pdf.');
        }

        return $format;
    }
}
