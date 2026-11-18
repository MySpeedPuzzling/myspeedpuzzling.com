<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingDivision;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingRounds;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionStandingHandler
{
    public function __construct(
        private CompetitionStandingRepository $standingRepository,
        private CompetitionStandingRounds $standingRounds,
        private CompetitionStandingDivision $standingDivision,
    ) {
    }

    /**
     * @throws CompetitionStandingNotFound also for a standing of another event
     * @throws InvalidCompetitionStanding
     */
    public function __invoke(EditCompetitionStanding $message): void
    {
        $standing = $this->standingRepository->get($message->standingId);

        if ($standing->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionStandingNotFound();
        }

        try {
            $name = CompetitionStanding::cleanName($message->name);
        } catch (InvalidArgumentException) {
            throw new InvalidCompetitionStanding('invalid_name');
        }

        $mode = $message->mode ?? $standing->mode;
        $roundIds = $this->standingRounds->validated($standing->competition, $message->category, $message->roundIds, $mode);
        // false = the division kept (still checked against the rounds - a kept division every round must use too)
        $divisionId = $message->divisionId === false ? $standing->division?->id->toString() : $message->divisionId;

        $standing->change(
            $name,
            $message->category,
            $roundIds,
            $message->rules,
            $mode,
            $message->numberedAcrossStages,
            $this->standingDivision->validated($standing->competition, $divisionId, $roundIds, $mode),
        );
    }
}
