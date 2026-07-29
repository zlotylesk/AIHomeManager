<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use App\Module\Budget\Infrastructure\Persistence\DoctrineTransactionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionRepositoryTest extends KernelTestCase
{
    private DoctrineTransactionRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineTransactionRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE budget_transactions');
    }

    public function testSaveAndFindByIdRoundTripsAllFields(): void
    {
        $date = new DateTimeImmutable('2026-07-15');
        $this->repository->save(new Transaction(
            't0000001-0000-0000-0000-000000000001',
            new Money(4999, 'PLN'),
            $date,
            'c-groceries',
            TransactionType::EXPENSE,
            'Weekly shop',
        ));
        $this->em->clear();

        $found = $this->repository->findById('t0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame(4999, $found->amount()->amountInCents());
        self::assertSame('PLN', $found->amount()->currency());
        self::assertSame('2026-07-15', $found->date()->format('Y-m-d'));
        self::assertSame('c-groceries', $found->categoryId());
        self::assertSame(TransactionType::EXPENSE, $found->type());
        self::assertSame('Weekly shop', $found->description());
    }

    public function testDescriptionHydratesAsRealNullWhenAbsent(): void
    {
        $this->repository->save(new Transaction(
            't0000002-0000-0000-0000-000000000001',
            new Money(500000, 'PLN'),
            new DateTimeImmutable('2026-07-01'),
            'c-salary',
            TransactionType::INCOME,
        ));
        $this->em->clear();

        $found = $this->repository->findById('t0000002-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertNull($found->description());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function testFindAllReturnsAllSavedTransactions(): void
    {
        $this->repository->save(new Transaction('t0000003-0000-0000-0000-000000000001', new Money(100), new DateTimeImmutable(), 'c-a', TransactionType::EXPENSE));
        $this->repository->save(new Transaction('t0000003-0000-0000-0000-000000000002', new Money(200), new DateTimeImmutable(), 'c-b', TransactionType::INCOME));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testRemoveDeletesTransaction(): void
    {
        $this->repository->save(new Transaction(
            't0000004-0000-0000-0000-000000000001',
            new Money(100),
            new DateTimeImmutable(),
            'c-a',
            TransactionType::EXPENSE,
        ));
        $this->em->clear();

        $loaded = $this->repository->findById('t0000004-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $this->repository->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repository->findById('t0000004-0000-0000-0000-000000000001'));
    }

    public function testNonDefaultCurrencyRoundTrips(): void
    {
        $this->repository->save(new Transaction(
            't0000005-0000-0000-0000-000000000001',
            new Money(1500, 'EUR'),
            new DateTimeImmutable('2026-07-20'),
            'c-travel',
            TransactionType::EXPENSE,
        ));
        $this->em->clear();

        $found = $this->repository->findById('t0000005-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('EUR', $found->amount()->currency());
    }

    public function testExistsForCategoryIsTrueWhenATransactionReferencesIt(): void
    {
        $this->repository->save(new Transaction(
            't0000006-0000-0000-0000-000000000001',
            new Money(100),
            new DateTimeImmutable(),
            'c-referenced',
            TransactionType::EXPENSE,
        ));
        $this->em->clear();

        self::assertTrue($this->repository->existsForCategory('c-referenced'));
    }

    public function testExistsForCategoryIsFalseWhenNoTransactionReferencesIt(): void
    {
        self::assertFalse($this->repository->existsForCategory('c-unused'));
    }
}
