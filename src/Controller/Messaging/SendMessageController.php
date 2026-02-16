<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Results\MessageView;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SendMessageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/en/messages/{conversationId}/send',
        name: 'send_message',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $conversationId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $content = trim($request->request->getString('message'));

        if ($content !== '') {
            $this->messageBus->dispatch(new SendMessage(
                conversationId: $conversationId,
                senderId: $loggedPlayer->playerId,
                content: $content,
            ));
        }

        if (str_contains($request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html') && $content !== '') {
            $messageView = new MessageView(
                messageId: Uuid::uuid7()->toString(),
                senderId: $loggedPlayer->playerId,
                senderName: $loggedPlayer->playerName,
                senderAvatar: $loggedPlayer->avatar,
                content: $content,
                sentAt: new DateTimeImmutable(),
                readAt: null,
                isOwnMessage: true,
            );

            return new Response(
                $this->renderView('messaging/_new_message_stream.html.twig', [
                    'message' => $messageView,
                ]),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html'],
            );
        }

        return $this->redirectToRoute('conversation_detail', ['conversationId' => $conversationId]);
    }
}
