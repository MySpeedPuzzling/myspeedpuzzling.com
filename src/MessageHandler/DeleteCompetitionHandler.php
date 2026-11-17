<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionHasResults;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplayImages;
use SpeedPuzzling\Web\Query\GetCompetitionDisplayImagePaths;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\RoundStopwatchReveal;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler]
readonly final class DeleteCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private Connection $database,
        private SecretPuzzleHides $secretPuzzleHides,
        private OfficialResultsGuard $officialResultsGuard,
        private CompetitionRoundRepository $competitionRoundRepository,
        private RoundStopwatchReveal $roundStopwatchReveal,
        private ClockInterface $clock,
        private GetCompetitionDisplayImagePaths $getCompetitionDisplayImagePaths,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(DeleteCompetition $message): void
    {
        $competitionId = $message->competitionId;
        $params = ['id' => $competitionId];

        if ($message->refuseWhenItHasResults) {
            $resultsCount = $this->database->fetchOne(
                'SELECT COUNT(*) FROM puzzle_solving_time
                 WHERE competition_id = :id
                    OR competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
                $params,
            );

            // Official results recorded by the organiser count as results too - a qualified mark as well
            $resultsCount = (is_numeric($resultsCount) ? (int) $resultsCount : 0)
                + $this->officialResultsGuard->countEntriesWithOfficialDataInCompetition($competitionId);

            if ($resultsCount > 0) {
                throw new CompetitionHasResults($resultsCount);
            }
        }

        // Secret puzzles of the rounds going away - re-synced afterwards from the rounds left, never revealed by accident
        /** @var array<string> $roundIds */
        $roundIds = $this->database->fetchFirstColumn('SELECT id FROM competition_round WHERE competition_id = :id', $params);
        // Locks the rounds, then their secret puzzles - waits for every other change of them (SecretPuzzleHides)
        $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange($roundIds);

        // A countdown with "Reveal the puzzles" still ahead never reveals at its moment once the rounds are gone: taken
        // back while their rows exist - without them the puzzles' dates stay as they are (RoundStopwatchReveal)
        if ($secretPuzzleIds !== []) {
            $now = $this->clock->now();

            foreach ($roundIds as $roundId) {
                $this->roundStopwatchReveal->withdrawBeforeRemoving($this->competitionRoundRepository->get($roundId)->roundPuzzles, $now);
            }
        }

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL
             WHERE competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        // An explicit link loses the event; a series pick matched to this edition becomes series-level (the match kind
        // goes with the edition) and is re-matched after the flush (recordRemoval() below)
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL, series_edition_match = NULL WHERE competition_id = :id',
            $params,
        );

        $this->database->executeStatement(
            'DELETE FROM competition_participant_round
             WHERE participant_id IN (SELECT id FROM competition_participant WHERE competition_id = :id)
                OR round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_team
             WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_participant WHERE competition_id = :id',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round_puzzle
             WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round WHERE competition_id = :id',
            $params,
        );

        // Its displays cascade with it - their uploaded pictures are deleted once the deletion committed
        $displayImages = $this->getCompetitionDisplayImagePaths->ofCompetition($competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        // An edition: its series' picks are re-matched once it is gone (SeriesEditionsChanged)
        $competition->recordRemoval();
        $this->competitionRepository->delete($competition);

        $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);

        if ($displayImages !== []) {
            $this->messageBus->dispatch(new DeleteCompetitionDisplayImages($displayImages), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
