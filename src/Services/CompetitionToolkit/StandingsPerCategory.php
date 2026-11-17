<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
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
}
