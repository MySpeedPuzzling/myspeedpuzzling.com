<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<DeleteSolvingTimeInput, void>
 */
final readonly class DeleteSolvingTimeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @param DeleteSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
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

        // The handler resolves the player by its user_id identity, like the edit
        $userId = $player->userId;

        if ($userId === null) {
            throw new AccessDeniedHttpException('Player account has no linked user login.');
        }

        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            via: SolvingTimeDeletedVia::Api,
        ));
    }
}
