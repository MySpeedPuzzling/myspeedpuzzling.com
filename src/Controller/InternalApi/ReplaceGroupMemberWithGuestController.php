<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ReplaceGroupMemberWithGuest;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A registered player added to somebody's pair/team result by mistake becomes the guest the tracker meant - in this one
 * result only. Body (optional): `guestName` (default: the player's own name). Answers the result's group as it is now;
 * 409 when the player is not a registered member of the result or tracked it, 404 for an unknown time or player.
 */
final class ReplaceGroupMemberWithGuestController extends AbstractController
{
    public const int GUEST_NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/solving-times/{timeId}/members/{playerId}/replace-with-guest',
        requirements: [
            'timeId' => FirstTryConflictsController::ID_REQUIREMENT,
            'playerId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['POST'],
    )]
    public function __invoke(string $timeId, string $playerId, Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['guestName']);
        $guestName = $input->string('guestName', maxLength: self::GUEST_NAME_MAX_LENGTH);
        $input->throwIfInvalid();

        $this->messageBus->dispatch(new ReplaceGroupMemberWithGuest(
            timeId: strtolower($timeId),
            playerId: strtolower($playerId),
            guestName: $guestName,
        ));

        $time = $this->puzzleSolvingTimeRepository->get(strtolower($timeId));

        $puzzlers = [];

        foreach ($time->team->puzzlers ?? [] as $puzzler) {
            $puzzlers[] = ['playerId' => $puzzler->playerId, 'guestName' => $puzzler->playerName];
        }

        return new JsonResponse([
            'timeId' => $time->id->toString(),
            'teamId' => $time->puzzlingTeam?->id->toString(),
            'puzzlers' => $puzzlers,
        ]);
    }
}
