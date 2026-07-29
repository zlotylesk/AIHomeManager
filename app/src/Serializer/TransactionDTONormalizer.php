<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Budget\Application\DTO\TransactionDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a TransactionDTO to its API array shape (HMAI-240). Pure field
 * mapping — the amount is already split into its two raw parts by the read
 * layer.
 */
final class TransactionDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof TransactionDTO);

        return [
            'id' => $data->id,
            'amountInCents' => $data->amountInCents,
            'currency' => $data->currency,
            'date' => $data->date,
            'categoryId' => $data->categoryId,
            'type' => $data->type,
            'description' => $data->description,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TransactionDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [TransactionDTO::class => true];
    }
}
