<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<UpdateSolvingTimeInput, SolvingTimeResponse>
 */
final readonly class UpdateSolvingTimeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @param UpdateSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $timeId */
        $timeId = $uriVariables['timeId'];

        $finishedAt = $data->finished_at !== null ? new DateTimeImmutable($data->finished_at) : null;

        $this->messageBus->dispatch(
            new EditPuzzleSolvingTime(
                currentUserId: $playerId,
                puzzleSolvingTimeId: $timeId,
                competitionId: null,
                time: $data->time,
                comment: $data->comment,
                groupPlayers: $data->group_players,
                finishedAt: $finishedAt,
                finishedPuzzlesPhoto: null,
                firstAttempt: $data->first_attempt,
                unboxed: $data->unboxed,
            ),
        );

        return new SolvingTimeResponse(
            time_id: $timeId,
            puzzle_id: '',
            time_seconds: null,
            finished_at: $finishedAt?->format('c'),
            first_attempt: $data->first_attempt,
            unboxed: $data->unboxed,
            comment: $data->comment,
        );
    }
}
