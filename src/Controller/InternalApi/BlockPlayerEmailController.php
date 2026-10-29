<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\BlockPlayerEmailAddress;
use SpeedPuzzling\Web\Query\GetPlayerEmailSuppression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Blocks the player's current address by hand: nothing at all is sent to it (sign-in links included) and the player
 * reads `reason` (required) in a banner asking them to check and change the address. Again with another reason =
 * the new reason. Answers the address's suppression; 409 when the player has no e-mail address.
 */
final class BlockPlayerEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPlayerEmailSuppression $getPlayerEmailSuppression,
    ) {
    }

    #[Route(
        path: '/internal-api/players/{playerId}/email-block',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $playerId, Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['reason']);
        $reason = $input->string('reason', required: true, maxLength: BlockPlayerEmailAddress::REASON_MAX_LENGTH);
        $input->throwIfInvalid();
        assert($reason !== null);

        $playerId = strtolower($playerId);
        $this->messageBus->dispatch(new BlockPlayerEmailAddress(playerId: $playerId, reason: $reason));

        return new JsonResponse($this->getPlayerEmailSuppression->ofPlayer($playerId));
    }
}
