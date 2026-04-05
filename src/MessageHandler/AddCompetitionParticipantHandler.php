<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Message\AddCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddCompetitionParticipant $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $message->name,
            country: $message->country,
            competition: $competition,
            source: ParticipantSource::Manual,
        );

        if ($message->externalId !== null) {
            $participant->updateExternalId($message->externalId);
        }

        if ($message->playerId !== null) {
            $player = $this->playerRepository->get($message->playerId);
            $participant->connect($player, $this->clock->now());
        }

        $this->participantRepository->save($participant);
    }
}
