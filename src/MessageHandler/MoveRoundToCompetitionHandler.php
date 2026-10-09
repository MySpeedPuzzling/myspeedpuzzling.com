<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Exceptions\RoundMovedMeanwhile;
use SpeedPuzzling\Web\Exceptions\RoundNotMovable;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\Restructuring\EventUrlRedirects;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\RoundNotMovableReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * docs/features/organizations/README.md "Restructuring tools", D7: the round row moves (its puzzles with their reveal
 * settings and its table layout hang on it), and so does every explicit solving time that belongs to it - their
 * competition changes, their round link stays. Series picks of the edition do not move: the series reconcile both
 * competitions get matches them again (docs/features/events-page/high-frequency-series.md P29). A time of the old competition that belongs to the round by the derived rule but was
 * not linked yet (SolvingTimeRoundResolver) moves too and gets the link, so no result of the round stays behind. Both
 * competitions' round results are reconciled after the flush (CompetitionRoundsChanged, recorded by the round). The
 * round keeps the wall-clock zone it is shown in (P22) and its slug - with `-2`, `-3`, … when the target has it. A
 * target hidden as a draft takes only a round without solving times (a draft never gets linked times).
 */
#[AsMessageHandler]
readonly final class MoveRoundToCompetitionHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $competitionTeamRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionRounds $getCompetitionRounds,
        private CompetitionSlugGenerator $slugGenerator,
        private EventUrlRedirects $eventUrlRedirects,
    ) {
    }

    /**
     * @throws RoundMovedMeanwhile
     * @throws RoundNotMovable
     * @throws PuzzleInTwoRoundsOfCategory
     */
    public function __invoke(MoveRoundToCompetition $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $source = $round->competition;

        if ($source->id->toString() !== strtolower($message->competitionId)) {
            throw new RoundMovedMeanwhile();
        }

        $target = $this->competitionRepository->get($message->targetCompetitionId);
        // Who moves it - the caller checked the rights, the player must exist
        $this->playerRepository->get($message->actingPlayerId);

        if ($target->id->equals($source->id)) {
            throw new RoundNotMovable(RoundNotMovableReason::SameCompetition);
        }

        if (
            $this->participantRoundRepository->findByRound($round) !== []
            || $this->competitionTeamRepository->findByRound($round) !== []
        ) {
            throw new RoundNotMovable(RoundNotMovableReason::HasEntries);
        }

        if ($round->stopwatchStatus === 'running') {
            throw new RoundNotMovable(RoundNotMovableReason::StopwatchRunning);
        }

        $puzzleIds = array_values(array_map(
            static fn (CompetitionRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->id->toString(),
            $round->roundPuzzles->toArray(),
        ));

        // One round per category per puzzle per competition - what lets a time's round follow from its competition
        $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
            competitionId: $target->id->toString(),
            puzzleIds: $puzzleIds,
            category: $round->category,
        );

        if ($conflictingRound !== null) {
            throw new PuzzleInTwoRoundsOfCategory($round->category->value, $conflictingRound);
        }

        $oldPath = $this->resultsPath($source, $round->slug);
        $times = $this->puzzleSolvingTimeRepository->findByCompetitionRound($round);

        // A draft never gets linked times - their listings would show its name. Entries (and with them official
        // results) are refused above for every target
        if ($times !== [] && $target->isHiddenAsDraft()) {
            throw new RoundNotMovable(RoundNotMovableReason::DraftTargetWithResults);
        }

        $round->moveToCompetition($target, $this->freeSlug($round, $target));

        foreach ($times as $time) {
            $time->competitionRoundMovedTo($target);

            if ($time->competitionRound === null) {
                $time->changeCompetitionRound($round);
            }
        }

        if ($oldPath !== null) {
            $this->eventUrlRedirects->remember($oldPath, $round);
        }
    }

    /**
     * The round's slug, or with `-2`, `-3`, … when another round of the target has it (round slugs are unique per
     * competition). A round without a slug gets one from its name, like a new round.
     */
    private function freeSlug(CompetitionRound $round, Competition $target): string
    {
        $base = $round->slug ?? $this->slugGenerator->normalize($round->name);
        $base = $base !== '' ? $base : 'round';
        $taken = array_flip($this->eventUrlRedirects->roundSlugs($target));

        $slug = $base;

        for ($suffix = 2; isset($taken[$slug]); $suffix++) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    /**
     * The round's results address as it is now - null when the round or its competition has no address
     */
    private function resultsPath(Competition $competition, null|string $roundSlug): null|EventUrlPath
    {
        if ($roundSlug === null || $competition->slug === null) {
            return null;
        }

        if ($competition->series === null) {
            return EventUrlPath::eventRound($competition->slug, $roundSlug);
        }

        return $competition->series->slug !== null
            ? EventUrlPath::editionRound($competition->series->slug, $competition->slug, $roundSlug)
            : null;
    }
}
