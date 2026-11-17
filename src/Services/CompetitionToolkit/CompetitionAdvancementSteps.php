<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The write-side check of an advancement step (competition toolkit A8): the step as it would be saved, together with
 * every other step of its event, against the event's rounds (AdvancementStepGraph).
 */
readonly final class CompetitionAdvancementSteps
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
    ) {
    }

    /**
     * @param list<CompetitionAdvancementStep> $existing the event's steps
     * @return AdvancementStepSettings normalised, ready to save
     * @throws InvalidAdvancementStep
     */
    public function validated(Competition $competition, array $existing, string $stepId, AdvancementStepSettings $settings): AdvancementStepSettings
    {
        $settings = $settings->normalised();
        $roundCategories = $this->roundCategories($competition);
        $steps = [];

        foreach ($existing as $step) {
            $steps[$step->id->toString()] = $step->settings()->normalised();
        }

        // The changed step is checked first, so its own mistakes are named before any clash with the others
        AdvancementStepGraph::validateStep($settings, $roundCategories);
        $steps[strtolower($stepId)] = $settings;

        // The other steps may be broken by a later round change (a pruned target, a changed category): only the clashes
        // with this one count - the steps page shows a broken step on its own
        AdvancementStepGraph::validate(
            array_filter($steps, static function (AdvancementStepSettings $step, string $id) use ($stepId, $roundCategories): bool {
                if ($id === strtolower($stepId)) {
                    return true;
                }

                try {
                    AdvancementStepGraph::validateStep($step, $roundCategories);
                } catch (InvalidAdvancementStep) {
                    return false;
                }

                return true;
            }, ARRAY_FILTER_USE_BOTH),
            $roundCategories,
        );

        return $settings;
    }

    /**
     * @return array<string, RoundCategory> lower-cased round id => category
     */
    public function roundCategories(Competition $competition): array
    {
        $categories = [];

        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $categories[$round->id->toString()] = $round->category;
        }

        return $categories;
    }
}
