<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Query\GetTransactionRatings;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\MercureTopicCollector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ConversationDetailController extends AbstractController
{
    public function __construct(
        readonly private ConversationRepository $conversationRepository,
        readonly private GetMessages $getMessages,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private MercureTopicCollector $mercureTopicCollector,
        readonly private GetTransactionRatings $getTransactionRatings,
    ) {
    }

    #[Route(
        path: '/en/messages/{conversationId}',
        name: 'conversation_detail',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $conversationId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $conversation = $this->conversationRepository->get($conversationId);

        // Verify current user is a participant
        $isInitiator = $conversation->initiator->id->toString() === $loggedPlayer->playerId;
        $isRecipient = $conversation->recipient->id->toString() === $loggedPlayer->playerId;

        if (!$isInitiator && !$isRecipient) {
            throw $this->createAccessDeniedException();
        }

        $otherPlayer = $isInitiator ? $conversation->recipient : $conversation->initiator;

        $messages = [];
        if ($conversation->status === ConversationStatus::Accepted) {
            $messages = $this->getMessages->forConversation($conversationId, $loggedPlayer->playerId);

            // Mark messages as read
            $this->messageBus->dispatch(new MarkMessagesAsRead(
                conversationId: $conversationId,
                playerId: $loggedPlayer->playerId,
            ));
        } elseif (in_array($conversation->status, [ConversationStatus::Pending, ConversationStatus::Ignored], true)) {
            // Both parties can see messages, but do NOT mark as read for recipient
            $messages = $this->getMessages->forConversation($conversationId, $loggedPlayer->playerId);
        }

        $puzzleContext = null;
        if ($conversation->puzzle !== null) {
            $puzzleContext = [
                'id' => $conversation->puzzle->id->toString(),
                'name' => $conversation->puzzle->name,
                'image' => $conversation->puzzle->image,
                'piecesCount' => $conversation->puzzle->piecesCount,
            ];

            if ($conversation->sellSwapListItem !== null) {
                $puzzleContext['listingType'] = $conversation->sellSwapListItem->listingType->value;
                $puzzleContext['price'] = $conversation->sellSwapListItem->price;
                $puzzleContext['itemId'] = $conversation->sellSwapListItem->id->toString();
                $puzzleContext['reserved'] = $conversation->sellSwapListItem->reserved;
            }
        }

        $this->mercureTopicCollector->addTopic('/messages/' . $conversationId . '/user/' . $loggedPlayer->playerId);
        $this->mercureTopicCollector->addTopic('/conversation/' . $conversationId . '/read/' . $loggedPlayer->playerId);
        $this->mercureTopicCollector->addTopic('/conversation/' . $conversationId . '/typing');

        $isSeller = $conversation->sellSwapListItem !== null
            && $conversation->sellSwapListItem->player->id->toString() === $loggedPlayer->playerId;

        $ratingInfo = null;
        if ($conversation->puzzle !== null && $conversation->sellSwapListItem === null) {
            $ratingInfo = $this->getTransactionRatings->forConversation(
                puzzleId: $conversation->puzzle->id->toString(),
                playerAId: $conversation->initiator->id->toString(),
                playerBId: $conversation->recipient->id->toString(),
                viewerId: $loggedPlayer->playerId,
            );
        }

        return $this->render('messaging/conversation_detail.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
            'other_player' => $otherPlayer,
            'is_recipient' => $isRecipient,
            'puzzle_context' => $puzzleContext,
            'is_seller' => $isSeller,
            'rating_info' => $ratingInfo,
        ]);
    }
}
