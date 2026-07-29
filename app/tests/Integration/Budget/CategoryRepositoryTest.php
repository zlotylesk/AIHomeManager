<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use App\Module\Budget\Infrastructure\Persistence\DoctrineCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CategoryRepositoryTest extends KernelTestCase
{
    private DoctrineCategoryRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineCategoryRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE budget_categories');
    }

    public function testSaveAndFindByIdRoundTripsAllFields(): void
    {
        $this->repository->save(new Category(
            'c0000001-0000-0000-0000-000000000001',
            'Zakupy spożywcze',
            TransactionType::EXPENSE,
            new Money(50000, 'PLN'),
        ));
        $this->em->clear();

        $found = $this->repository->findById('c0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('Zakupy spożywcze', $found->name());
        self::assertSame(TransactionType::EXPENSE, $found->type());
        $limit = $found->monthlyLimit();
        self::assertNotNull($limit);
        self::assertSame(50000, $limit->amountInCents());
        self::assertSame('PLN', $limit->currency());
    }

    public function testUnlimitedCategoryHydratesRealNullNotABrokenMoney(): void
    {
        $this->repository->save(new Category(
            'c0000002-0000-0000-0000-000000000001',
            'Wynagrodzenie',
            TransactionType::INCOME,
        ));
        $this->em->clear();

        $found = $this->repository->findById('c0000002-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        // The custom budget_money DBAL type must return a real null for a NULL
        // column, not a hydrated-but-broken Money (the nullable-embeddable hazard).
        self::assertNull($found->monthlyLimit());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function testFindAllReturnsAllSavedCategories(): void
    {
        $this->repository->save(new Category('c0000003-0000-0000-0000-000000000001', 'Rachunki', TransactionType::EXPENSE));
        $this->repository->save(new Category('c0000003-0000-0000-0000-000000000002', 'Premia', TransactionType::INCOME));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testRemoveDeletesCategory(): void
    {
        $this->repository->save(new Category('c0000004-0000-0000-0000-000000000001', 'Transport', TransactionType::EXPENSE));
        $this->em->clear();

        $loaded = $this->repository->findById('c0000004-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $this->repository->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repository->findById('c0000004-0000-0000-0000-000000000001'));
    }

    public function testNameSurvivesTheRoundTripAtFullColumnWidth(): void
    {
        $longName = str_repeat('ż', 255);
        $this->repository->save(new Category('c0000005-0000-0000-0000-000000000001', $longName, TransactionType::EXPENSE));
        $this->em->clear();

        $found = $this->repository->findById('c0000005-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame($longName, $found->name());
    }
}
