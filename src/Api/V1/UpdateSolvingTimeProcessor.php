<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<UpdateSolvingTimeInput, SolvingTimeResponse>
 */
final readonly class UpdateSolvingTimeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @param UpdateSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $player = $user->getPlayer();

        /** @var string $timeId */
        $timeId = $uriVariables['timeId'];

        // Validate here so an invalid/unknown id surfaces as 404/403 instead of a wrapped 500 from the handler
        $solvingTime = $this->puzzleSolvingTimeRepository->get($timeId);

        if ($solvingTime->player->id->equals($player->id) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        // The handler resolves the player by auth0 user id (and creates one when missing),
        // so passing the player uuid here would attribute the edit to a phantom player
        $userId = $player->userId;

        if ($userId === null) {
            throw new AccessDeniedHttpException('Player account has no linked user login.');
        }

        $finishedAt = $data->finished_at !== null ? new DateTimeImmutable($data->finished_at) : null;

        $this->messageBus->dispatch(
            new EditPuzzleSolvingTime(
                currentUserId: $userId,
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
            puzzle_id: $solvingTime->puzzle->id->toString(),
            time_seconds: null,
            finished_at: $finishedAt?->format('c'),
            first_attempt: $data->first_attempt,
            unboxed: $data->unboxed,
            comment: $data->comment,
        );
    }
}
