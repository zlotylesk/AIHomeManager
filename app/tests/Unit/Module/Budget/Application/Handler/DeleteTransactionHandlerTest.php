<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\DeleteTransaction;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Application\Handler\DeleteTransactionHandler;
use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use App\Module\Budget\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DeleteTransactionHandlerTest extends TestCase
{
    public function testRemovesTransaction(): void
    {
        $transaction = new Transaction('t-1', new Money(1000, 'PLN'), new DateTimeImmutable(), 'c-1', TransactionType::EXPENSE);

        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->method('findById')->willReturn($transaction);
        $repo->expects(self::once())->method('remove')->with($transaction);

        $handler = new DeleteTransactionHandler($repo);
        $handler(new DeleteTransaction('t-1'));
    }

    public function testThrowsWhenTransactionNotFound(): void
    {
        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $handler = new DeleteTransactionHandler($repo);

        $this->expectException(TransactionNotFoundException::class);
        $handler(new DeleteTransaction('missing'));
    }
}
