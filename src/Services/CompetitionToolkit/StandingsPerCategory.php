<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "one standing per category" preset (competition toolkit A6, docs/features/competitions-management/standings.md):
 * for every category with rounds and no standing yet, a standing over all its rounds with the default rules, named
 * "Overall - Solo". One rule for both of its callers - the standings page's button (CreateStandingsPerCategoryController)
 * and the creation wizard's "add up the times per category" (EventWizardStructureController, W7), which plans it for
 * rounds created in the same transaction.
 */
readonly final class StandingsPerCategory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, list<string>> $roundIdsByCategory RoundCategory value => the category's round ids, in order
     * @param list<RoundCategory> $covered categories that have a standing already
     * @return list<CreateCompetitionStanding> in the order of RoundCategory::cases()
     */
    public function messages(string $competitionId, array $roundIdsByCategory, array $covered = []): array
    {
        $messages = [];

        foreach (RoundCategory::cases() as $category) {
            $roundIds = $roundIdsByCategory[$category->value] ?? [];

            if ($roundIds === [] || in_array($category, $covered, true)) {
                continue;
            }

            $messages[] = new CreateCompetitionStanding(
                standingId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: $this->translator->trans('competition_standing.per_category_name', [
                    '%category%' => $this->translator->trans('competition.round.category.' . $category->value),
                ]),
                category: $category,
                roundIds: $roundIds,
                rules: new StandingRules(),
            );
        }

        return $messages;
    }

    /**
     * "One standing per category and division" (real events L2a, shared-puzzles-and-divisions.md D7): for every category
     * and every division its rounds use, a standing of that division over the category's rounds that use it, the default
     * rules, named "Overall - Solo - Senior 55+" - unless the category has a standing of that division already.
     *
     * @param array<string, list<string>> $roundIdsByCategory RoundCategory value => the category's round ids, in order
     * @param list<CompetitionDivisionOverview> $divisions the event's divisions in its order, each with its rounds
     * @param list<string> $covered "category|divisionId" of the standings limited to a division already
     * @return list<CreateCompetitionStanding> by category (RoundCategory::cases()), then the event's division order
     */
    public function perDivision(string $competitionId, array $roundIdsByCategory, array $divisions, array $covered = []): array
    {
        $messages = [];

        foreach (RoundCategory::cases() as $category) {
            foreach ($divisions as $division) {
                $divisionId = strtolower($division->id);
                $using = array_map(strtolower(...), $division->roundIds);
                $roundIds = array_values(array_filter(
                    $roundIdsByCategory[$category->value] ?? [],
                    static fn (string $roundId): bool => in_array(strtolower($roundId), $using, true),
                ));

                if ($roundIds === [] || in_array(self::coverKey($category, $divisionId), $covered, true)) {
                    continue;
                }

                $messages[] = new CreateCompetitionStanding(
                    standingId: Uuid::uuid7(),
                    competitionId: $competitionId,
                    name: $this->translator->trans('standing_division.per_division_name', [
                        '%category%' => $this->translator->trans('competition.round.category.' . $category->value),
                        '%division%' => $division->name,
                    ]),
                    category: $category,
                    roundIds: $roundIds,
                    rules: new StandingRules(),
                    divisionId: $divisionId,
                );
            }
        }

        return $messages;
    }

    /**
     * The categories that have an overall standing (one not limited to a division) - what messages() skips.
     *
     * @param list<CompetitionStandingOverview> $standings
     * @return list<RoundCategory>
     */
    public static function coveredCategories(array $standings): array
    {
        return array_values(array_map(
            static fn (CompetitionStandingOverview $standing): RoundCategory => $standing->category,
            array_filter($standings, static fn (CompetitionStandingOverview $standing): bool => $standing->division === null),
        ));
    }

    /**
     * The category + division pairs that have a standing of the division - what perDivision() skips.
     *
     * @param list<CompetitionStandingOverview> $standings
     * @return list<string>
     */
    public static function coveredDivisions(array $standings): array
    {
        $covered = [];

        foreach ($standings as $standing) {
            if ($standing->division !== null) {
                $covered[] = self::coverKey($standing->category, $standing->division->id);
            }
        }

        return $covered;
    }

    /**
     * What perDivision() reads as "this category has a standing of this division already".
     */
    public static function coverKey(RoundCategory $category, string $divisionId): string
    {
        return $category->value . '|' . strtolower($divisionId);
    }
}
