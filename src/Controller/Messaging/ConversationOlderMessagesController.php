<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ConversationOlderMessagesController extends AbstractController
{
    public function __construct(
        readonly private ConversationRepository $conversationRepository,
        readonly private GetMessages $getMessages,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/en/messages/{conversationId}/older-messages',
        name: 'conversation_older_messages',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $conversationId, Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $conversation = $this->conversationRepository->get($conversationId);

        // Verify current user is a participant
        $isParticipant = $conversation->initiator?->id->toString() === $loggedPlayer->playerId
            || $conversation->recipient?->id->toString() === $loggedPlayer->playerId;

        if (!$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        $beforeMessageId = $request->query->getString('before');

        if (!Uuid::isValid($beforeMessageId)) {
            throw new BadRequestHttpException('Invalid "before" message id.');
        }

        $messagesPage = $this->getMessages->forConversation(
            conversationId: $conversationId,
            viewerId: $loggedPlayer->playerId,
            beforeMessageId: $beforeMessageId,
        );

        return $this->render('messaging/_older_messages.html.twig', [
            'messages' => $messagesPage->messages,
            'has_older_messages' => $messagesPage->hasOlderMessages,
            'oldest_message_id' => $messagesPage->oldestMessageId(),
        ]);
    }
}
