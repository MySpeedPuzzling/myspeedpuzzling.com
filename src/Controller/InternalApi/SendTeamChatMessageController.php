<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\SendTeamChatMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sends `text` (required, plain text) as a chat message from INTERNAL_API_REVIEWER_PLAYER_ID to the player: it lands
 * in their messages (unread badge, the player can answer) - the way to reach somebody whose e-mail does not arrive.
 */
final class SendTeamChatMessageController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/players/{playerId}/messages',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $playerId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to send the message from.',
            );
        }

        $input = InternalApiInput::fromRequest($request, ['text']);
        $text = $input->string('text', required: true, maxLength: SendTeamChatMessage::CONTENT_MAX_LENGTH);
        $input->throwIfInvalid();
        assert($text !== null);

        $this->messageBus->dispatch(new SendTeamChatMessage(
            senderId: $this->reviewerPlayerId,
            recipientId: strtolower($playerId),
            content: $text,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
