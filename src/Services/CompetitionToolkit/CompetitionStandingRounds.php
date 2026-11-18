<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionAdvancementStepRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMode;

/**
 * The write-side check of a standing's rounds (competition toolkit A6): at least one, each a round of the standing's own
 * event and category, none twice (a repeated id is kept once, in its first place).
 *
 * A `stages` standing (competition toolkit v2 S5) keeps no round list - its rounds are the bracket's, read from the
 * advancement steps at compute time; it needs a bracket of its category now (`no_steps`: no valid step of the category,
 * or the event's steps clash - the bracket page's own rule, BracketLayout).
 */
readonly final class CompetitionStandingRounds
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionAdvancementStepRepository $advancementStepRepository,
    ) {
    }

    /**
     * @param list<string> $roundIds ignored for `stages`
     * @return list<string> lower-cased, in the given order - empty for `stages`
     * @throws InvalidCompetitionStanding
     */
    public function validated(Competition $competition, RoundCategory $category, array $roundIds, StandingMode $mode = StandingMode::Sum): array
    {
        if ($mode === StandingMode::Stages) {
            if (!$this->hasBracket($competition, $category)) {
                throw new InvalidCompetitionStanding('no_steps');
            }

            return [];
        }

        $roundIds = array_values(array_unique(array_map(strtolower(...), $roundIds)));

        if ($roundIds === []) {
            throw new InvalidCompetitionStanding('no_rounds');
        }

        if (count($roundIds) > CompetitionStanding::MAX_ROUNDS) {
            throw new InvalidCompetitionStanding('too_many_rounds');
        }

        $categories = [];
        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $categories[$round->id->toString()] = $round->category;
        }

        foreach ($roundIds as $roundId) {
            if (!isset($categories[$roundId])) {
                throw new InvalidCompetitionStanding('unknown_round');
            }

            if ($categories[$roundId] !== $category) {
                throw new InvalidCompetitionStanding('wrong_category');
            }
        }

        return $roundIds;
    }

    /**
     * The event has a bracket of the category: a step that works as declared feeds rounds of it, and the working steps
     * do not clash (BracketLayout::of() gives no bracket at all then).
     */
    private function hasBracket(Competition $competition, RoundCategory $category): bool
    {
        $categories = [];
        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $categories[$round->id->toString()] = $round->category;
        }

        /** @var array<string, AdvancementStepSettings> $valid */
        $valid = [];
        $ofCategory = false;

        foreach ($this->advancementStepRepository->ofCompetition($competition) as $step) {
            $settings = $step->settings()->normalised();

            try {
                AdvancementStepGraph::validateStep($settings, $categories);
            } catch (InvalidAdvancementStep) {
                continue;
            }

            $valid[$step->id->toString()] = $settings;
            $ofCategory = $ofCategory || ($categories[$settings->sourceRoundIds[0] ?? ''] ?? null) === $category;
        }

        if ($ofCategory === false) {
            return false;
        }

        try {
            AdvancementStepGraph::validate($valid, $categories);
        } catch (InvalidAdvancementStep) {
            return false;
        }

        return true;
    }
}
