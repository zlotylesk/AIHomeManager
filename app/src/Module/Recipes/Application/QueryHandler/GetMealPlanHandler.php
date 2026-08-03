<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\QueryHandler;

use App\Module\Recipes\Application\DTO\MealPlanDayDTO;
use App\Module\Recipes\Application\DTO\MealPlanDTO;
use App\Module\Recipes\Application\DTO\MealPlanSlotDTO;
use App\Module\Recipes\Application\DTO\PlannedMealDTO;
use App\Module\Recipes\Application\Query\GetMealPlan;
use App\Module\Recipes\Domain\Enum\MealSlot;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMealPlanHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetMealPlan $query): MealPlanDTO
    {
        return new MealPlanDTO(
            from: $query->from->format('Y-m-d'),
            to: $query->to->format('Y-m-d'),
            days: $this->buildDays($query, $this->fetchPlanned($query)),
        );
    }

    /**
     * The whole window in one query, indexed by date and slot.
     *
     * The recipe is reached with a LEFT JOIN rather than an INNER JOIN so a
     * plan row whose recipe went missing still comes back (see
     * `PlannedMealDTO`), and the ordering is stated rather than left to the
     * engine: two meals in the same slot would otherwise come back in whatever
     * order the storage felt like, so a page reload could reshuffle the same
     * unchanged plan in front of the user. A row that lost its recipe sorts
     * last rather than first — MySQL puts NULL at the top of an ASC sort, which
     * would head the slot with the one card that cannot be named.
     *
     * @return array<string, array<string, list<PlannedMealDTO>>>
     */
    private function fetchPlanned(GetMealPlan $query): array
    {
        $sql = <<<'SQL'
            SELECT
                m.id,
                m.date,
                m.slot,
                m.recipe_id,
                m.servings,
                r.title AS recipe_title
            FROM meal_plan m
            LEFT JOIN recipes r ON r.id = m.recipe_id
            WHERE m.date BETWEEN :from AND :to
            ORDER BY m.date ASC, r.title IS NULL, r.title ASC, m.id ASC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'from' => $query->from->format('Y-m-d'),
            'to' => $query->to->format('Y-m-d'),
        ]);

        $planned = [];

        foreach ($rows as $row) {
            $date = (string) $row['date'];
            $slot = (string) $row['slot'];

            $planned[$date][$slot][] = new PlannedMealDTO(
                id: (string) $row['id'],
                recipeId: (string) $row['recipe_id'],
                recipeTitle: null === $row['recipe_title'] ? null : (string) $row['recipe_title'],
                servings: (int) $row['servings'],
            );
        }

        return $planned;
    }

    /**
     * Lay the window out day by day, slot by slot, filling in what nothing was
     * planned for. The shape of the response therefore depends only on the
     * window, never on how much of it is used — which is what lets a calendar
     * render a grid straight from the payload.
     *
     * @param array<string, array<string, list<PlannedMealDTO>>> $planned
     *
     * @return list<MealPlanDayDTO>
     */
    private function buildDays(GetMealPlan $query, array $planned): array
    {
        $days = [];

        // Counted off the window rather than walked with a `<= $to`
        // comparison, and each day derived from `from` rather than from its
        // predecessor. In a zone where a DST jump skips midnight, an
        // accumulating walk lands at 01:00 and then compares greater than a
        // `to` still sitting at 00:00 — quietly dropping the last day of the
        // plan. Deriving from a fixed base makes the day count exact by
        // construction, and it is already bounded by the query's own guard.
        for ($offset = 0; $offset < $query->dayCount(); ++$offset) {
            $date = $query->from->modify(sprintf('+%d days', $offset))->format('Y-m-d');
            $slots = [];

            foreach (MealSlot::cases() as $slot) {
                $slots[] = new MealPlanSlotDTO(
                    slot: $slot->value,
                    meals: $planned[$date][$slot->value] ?? [],
                );
            }

            $days[] = new MealPlanDayDTO($date, $slots);
        }

        return $days;
    }
}
