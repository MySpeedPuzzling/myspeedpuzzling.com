<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionReferee;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Repository\CompetitionRefereeRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\CompetitionRefereeAddition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddCompetitionRefereeHandler
{
    // Far above any real event (WJPC runs with a few dozen) - only stops a runaway form
    public const int MAX_REFEREES = 200;

    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private CompetitionRefereeRepository $refereeRepository,
        private GetCompetitionPermissions $getCompetitionPermissions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws PlayerNotFound when the organiser adding them does not exist
     */
    public function __invoke(AddCompetitionReferee $message): CompetitionRefereeAddition
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return CompetitionRefereeAddition::UnknownPlayer;
        }

        if ($this->getCompetitionPermissions->forPlayer($player->id->toString())->canEditCompetition($competition->id->toString())) {
            return CompetitionRefereeAddition::Organiser;
        }

        if ($this->refereeRepository->findOne($competition, $player) !== null) {
            return CompetitionRefereeAddition::AlreadyReferee;
        }

        if ($this->refereeRepository->countOf($competition) >= self::MAX_REFEREES) {
            return CompetitionRefereeAddition::LimitReached;
        }

        $this->refereeRepository->save(new CompetitionReferee(
            id: Uuid::uuid7(),
            competition: $competition,
            player: $player,
            addedBy: $this->playerRepository->get($message->addedByPlayerId),
            addedAt: $this->clock->now(),
        ));

        return CompetitionRefereeAddition::Added;
    }
}
