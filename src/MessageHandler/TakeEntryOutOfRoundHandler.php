<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\RoundEntryNotFound;
use SpeedPuzzling\Web\Message\TakeEntryOutOfRound;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class TakeEntryOutOfRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
    ) {
    }

    /**
     * Everything is checked before anything is removed.
     *
     * @throws CompetitionRoundNotFound
     * @throws RoundEntryNotFound
     * @throws OfficialResultsProtected
     */
    public function __invoke(TakeEntryOutOfRound $message): void
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $ref = RoundEntryRef::tryFromString($message->entry) ?? throw new RoundEntryNotFound();
        $roundId = $round->id->toString();

        if ($round->category === RoundCategory::Solo) {
            $participantRound = $ref->isTeam() ? null : $this->participantRoundRepository->find($ref->id);

            if ($participantRound === null || $participantRound->round->id->toString() !== $roundId || $participantRound->team !== null) {
                throw new RoundEntryNotFound();
            }

            if ($participantRound->hasOfficialData()) {
                throw new OfficialResultsProtected(OfficialResultsProtected::ROUND_ENTRY_HAS_RESULT);
            }

            $this->participantRoundRepository->delete($participantRound);

            return;
        }

        $team = $ref->isTeam() ? $this->teamRepository->find($ref->id) : null;

        if ($team === null || $team->round->id->toString() !== $roundId) {
            throw new RoundEntryNotFound();
        }

        if ($team->hasOfficialData()) {
            throw new OfficialResultsProtected(OfficialResultsProtected::TEAM_HAS_RESULT);
        }

        // The members are in the round only as this pair/team - their places in it go with it
        foreach ($this->participantRoundRepository->findByTeam($team) as $participantRound) {
            $this->participantRoundRepository->delete($participantRound);
        }

        $this->teamRepository->delete($team);
    }
}
