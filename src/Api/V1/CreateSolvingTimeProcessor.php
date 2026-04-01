<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<CreateSolvingTimeInput, SolvingTimeResponse>
 */
final readonly class CreateSolvingTimeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @param CreateSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();
        $timeId = Uuid::uuid7();

        $finishedAt = $data->finished_at !== null ? new DateTimeImmutable($data->finished_at) : null;

        $this->messageBus->dispatch(
            new AddPuzzleSolvingTime(
                timeId: $timeId,
                userId: $playerId,
                puzzleId: $data->puzzle_id,
                competitionId: null,
                time: $data->time,
                comment: $data->comment,
                finishedPuzzlesPhoto: null,
                groupPlayers: $data->group_players,
                finishedAt: $finishedAt,
                firstAttempt: $data->first_attempt,
                unboxed: $data->unboxed,
            ),
        );

        return new SolvingTimeResponse(
            time_id: $timeId->toString(),
            puzzle_id: $data->puzzle_id,
            time_seconds: null,
            finished_at: $finishedAt?->format('c'),
            first_attempt: $data->first_attempt,
            unboxed: $data->unboxed,
            comment: $data->comment,
        );
    }
}
