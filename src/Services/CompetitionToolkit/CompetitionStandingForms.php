<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\FormData\CompetitionStandingFormData;
use SpeedPuzzling\Web\FormType\CompetitionStandingFormType;
use SpeedPuzzling\Web\Query\GetBracketRounds;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMode;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The standing form of the add and edit pages (competition toolkit A6): the event's rounds labelled "Name · Category".
 * The "final classification across the bracket" choice (v2 S5) is offered once the event has a bracket of a category
 * (bracketCategories(): the bracket page's own layout over the declared advancement steps).
 */
readonly final class CompetitionStandingForms
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private GetBracketRounds $getBracketRounds,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<RoundCategory> $bracketCategories bracketCategories() of the event
     * @return FormInterface<CompetitionStandingFormData>
     */
    public function create(string $competitionId, CompetitionStandingFormData $data, string $action, array $bracketCategories = []): FormInterface
    {
        $labels = [];

        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            $labels[$round->id] = $round->name . ' · ' . $this->translator->trans('competition.round.category.' . $round->category->value);
        }

        return $this->formFactory->create(CompetitionStandingFormType::class, $data, [
            'action' => $action,
            'rounds' => $labels,
            // A standing classified across a bracket stays editable after its steps went away (the handler refuses it)
            'stages_available' => $bracketCategories !== [] || $data->mode === StandingMode::Stages,
        ]);
    }

    /**
     * The categories the event has a bracket of - those a `stages` standing can classify.
     *
     * @return list<RoundCategory>
     */
    public function bracketCategories(string $competitionId): array
    {
        $steps = $this->getCompetitionAdvancementSteps->ofCompetition($competitionId);

        if ($steps === []) {
            return [];
        }

        return array_map(
            static fn (Bracket $bracket): RoundCategory => $bracket->category,
            BracketLayout::of($steps, $this->getBracketRounds->ofCompetition($competitionId)),
        );
    }
}
