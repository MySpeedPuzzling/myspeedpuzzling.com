<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnblockPlayerEmailAddress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lifts a block by hand from the player's current address (the whole suppression goes, bounces included). 404 when the
 * address is not blocked by hand - a bounce suppression alone is never lifted here.
 */
final class UnblockPlayerEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/internal-api/players/{playerId}/email-block',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['DELETE'],
    )]
    public function __invoke(string $playerId): Response
    {
        $this->messageBus->dispatch(new UnblockPlayerEmailAddress(playerId: strtolower($playerId)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
