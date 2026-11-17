<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\EventWizardStructureLocked;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Message\SetUpEventWizardStructure;
use SpeedPuzzling\Web\Query\GetEventWizardStructureLock;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Services\EventWizard\StandingsJoiningNewRounds;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every message runs nested in this handler's transaction (each in its own savepoint, flushed before the next one
 * reads), like SetUpEventWizardRoundHandler: whatever refuses rolls back everything the step wrote. The page's check
 * "no entry, result or stopwatch run yet" is made again here, under the event's participants lock (SerializedByLock),
 * before anything existing is touched - a round delete takes its entries with it.
 */
#[AsMessageHandler]
readonly final class SetUpEventWizardStructureHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private GetEventWizardStructureLock $structureLock,
        private CompetitionRepository $competitionRepository,
        private CompetitionStandingRepository $standingRepository,
        private CompetitionRoundRepository $roundRepository,
    ) {
    }

    /**
     * @throws EventWizardStructureLocked
     */
    public function __invoke(SetUpEventWizardStructure $message): void
    {
        $changesExisting = $message->replaceStandings || $message->deletes !== [] || $message->edits !== [];

        if ($changesExisting && $this->structureLock->isLocked($message->competitionId)) {
            throw new EventWizardStructureLocked();
        }

        if ($message->replaceStandings) {
            foreach ($this->standingRepository->ofCompetition($this->competitionRepository->get($message->competitionId)) as $standing) {
                $this->messageBus->dispatch(new DeleteCompetitionStanding($standing->id->toString(), $message->competitionId));
            }
        }

        // Decided on the event as it is before this step: a standing of every round of its category gets the new ones
        $joining = $message->joinStandings && $message->adds !== [] ? $this->standingsJoining($message->competitionId) : [];

        foreach ([...$message->deletes, ...$message->edits, ...$message->adds, ...$message->advancementSteps, ...$message->standings] as $inner) {
            $this->messageBus->dispatch($inner);
        }

        foreach ($joining as $standing) {
            $this->join($standing, $message);
        }
    }

    /**
     * @return list<CompetitionStanding>
     */
    private function standingsJoining(string $competitionId): array
    {
        $competition = $this->competitionRepository->get($competitionId);
        $standings = $this->standingRepository->ofCompetition($competition);
        $joiningIds = StandingsJoiningNewRounds::of(
            array_map(static fn (CompetitionStanding $standing): array => [
                'id' => $standing->id->toString(),
                'category' => $standing->category,
                'roundIds' => $standing->roundIds(),
                'published' => $standing->publishedAt !== null,
            ], $standings),
            $this->roundCategories($competitionId),
        );

        return array_values(array_filter($standings, static fn (CompetitionStanding $standing): bool => in_array($standing->id->toString(), $joiningIds, true)));
    }

    /**
     * The standing with the new rounds of its category after its rounds as they are now (a round removed or moved to
     * another category in the same step is no longer one of them) - its name and rules kept
     */
    private function join(CompetitionStanding $standing, SetUpEventWizardStructure $message): void
    {
        $newIds = array_values(array_map(
            static fn (AddCompetitionRound $add): string => $add->roundId->toString(),
            array_filter($message->adds, static fn (AddCompetitionRound $add): bool => $add->category === $standing->category),
        ));

        if ($newIds === []) {
            return;
        }

        $rounds = $this->roundCategories($message->competitionId);
        $kept = array_values(array_filter(
            $standing->roundIds(),
            static fn (string $roundId): bool => ($rounds[$roundId] ?? null) === $standing->category && in_array($roundId, $newIds, true) === false,
        ));
        $roundIds = [...$kept, ...$newIds];

        if (count($roundIds) > CompetitionStanding::MAX_ROUNDS) {
            return;
        }

        $this->messageBus->dispatch(new EditCompetitionStanding(
            standingId: $standing->id->toString(),
            competitionId: $message->competitionId,
            name: $standing->name,
            category: $standing->category,
            roundIds: $roundIds,
            rules: $standing->rules(),
        ));
    }

    /**
     * @return array<string, RoundCategory> the event's rounds now: id => category
     */
    private function roundCategories(string $competitionId): array
    {
        $categories = [];

        foreach ($this->roundRepository->ofCompetition($this->competitionRepository->get($competitionId)) as $round) {
            $categories[$round->id->toString()] = $round->category;
        }

        return $categories;
    }
}
