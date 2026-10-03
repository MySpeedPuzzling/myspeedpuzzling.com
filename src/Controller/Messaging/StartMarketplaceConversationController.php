<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\ConversationRequestAlreadyPending;
use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\EventDateFormatter;
use SpeedPuzzling\Web\Services\MercureTopicCollector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class StartMarketplaceConversationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private TranslatorInterface $translator,
        readonly private ConversationRepository $conversationRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private GetMessages $getMessages,
        readonly private MercureTopicCollector $mercureTopicCollector,
        readonly private GetMarketplaceEvents $getMarketplaceEvents,
        readonly private EventDateFormatter $eventDateFormatter,
    ) {
    }

    #[Route(
        path: '/en/messages/new/offer/{sellSwapListItemId}',
        name: 'start_marketplace_conversation',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $sellSwapListItemId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $sellSwapListItem = $this->sellSwapListItemRepository->get($sellSwapListItemId);
        $puzzle = $this->getPuzzleOverview->byId($sellSwapListItem->puzzle->id->toString());
        $recipientId = $sellSwapListItem->player->id->toString();
        $recipient = $this->getPlayerProfile->byId($recipientId);
        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        // When the seller opens the conversation link, redirect to conversation with the reservee
        if ($recipientId === $loggedPlayer->playerId) {
            if ($sellSwapListItem->reservedForPlayerId !== null) {
                $recipientId = $sellSwapListItem->reservedForPlayerId->toString();
                $recipient = $this->getPlayerProfile->byId($recipientId);
            } else {
                $this->addFlash('warning', $this->translator->trans('messaging.cannot_contact_yourself'));

                return $this->redirectToRoute('conversations_list');
            }
        }

        $loggedPlayerEntity = $this->playerRepository->get($loggedPlayer->playerId);
        $recipientEntity = $this->playerRepository->get($recipientId);

        // "Ask to bring it" (?event=): the request written for the buyer, who can still edit it
        $prefillMessage = $request->isMethod('GET')
            ? $this->askToBringMessage($request, $loggedPlayer, $sellSwapListItem, $puzzle)
            : null;

        $existingConversation = $this->conversationRepository->findActiveByPlayersAndListing(
            $loggedPlayerEntity,
            $recipientEntity,
            $sellSwapListItem,
        );

        if ($existingConversation !== null) {
            $isRecipient = $existingConversation->recipient?->id->toString() === $loggedPlayer->playerId;

            // Handle sending message to existing conversation
            if ($request->isMethod('POST')) {
                $messageContent = trim($request->request->getString('message'));

                if ($messageContent !== '') {
                    try {
                        $this->messageBus->dispatch(new SendMessage(
                            conversationId: $existingConversation->id->toString(),
                            senderId: $loggedPlayer->playerId,
                            content: $messageContent,
                        ));
                    } catch (ConversationNotFound) {
                        // Deleted between loading this page and sending.
                        $this->addFlash('warning', $this->translator->trans('messaging.conversation_unavailable'));

                        return $this->redirectToRoute('conversations_list');
                    }
                }
            }

            $messages = $this->getMessages->forConversation($existingConversation->id->toString(), $loggedPlayer->playerId)->messages;

            if ($existingConversation->status === ConversationStatus::Accepted) {
                $this->messageBus->dispatch(new MarkMessagesAsRead(
                    conversationId: $existingConversation->id->toString(),
                    playerId: $loggedPlayer->playerId,
                ));
            }

            $conversationIdStr = $existingConversation->id->toString();
            $this->mercureTopicCollector->addTopic('/messages/' . $conversationIdStr . '/user/' . $loggedPlayer->playerId);
            $this->mercureTopicCollector->addTopic('/conversation/' . $conversationIdStr . '/read/' . $loggedPlayer->playerId);
            $this->mercureTopicCollector->addTopic('/conversation/' . $conversationIdStr . '/typing');

            return $this->render(
                $isModal ? 'messaging/start_marketplace_conversation_modal.html.twig' : 'messaging/start_conversation.html.twig',
                [
                    'recipient' => $recipient,
                    'sell_swap_list_item' => $sellSwapListItem,
                    'puzzle' => $puzzle,
                    'conversation' => $existingConversation,
                    'messages' => $messages,
                    'is_recipient' => $isRecipient,
                    'prefill_message' => $prefillMessage,
                ],
            );
        }

        $templateParams = [
            'recipient' => $recipient,
            'sell_swap_list_item' => $sellSwapListItem,
            'puzzle' => $puzzle,
            'prefill_message' => $prefillMessage,
        ];

        if ($request->isMethod('POST')) {
            $messageContent = trim($request->request->getString('message'));

            if ($messageContent === '') {
                $this->addFlash('danger', $this->translator->trans('messaging.message_empty'));

                return $this->render(
                    $isModal ? 'messaging/start_marketplace_conversation_modal.html.twig' : 'messaging/start_conversation.html.twig',
                    $templateParams,
                );
            }

            try {
                $this->messageBus->dispatch(new StartConversation(
                    initiatorId: $loggedPlayer->playerId,
                    recipientId: $recipientId,
                    initialMessage: $messageContent,
                    sellSwapListItemId: $sellSwapListItemId,
                    puzzleId: $sellSwapListItem->puzzle->id->toString(),
                ));

                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('messaging/_start_conversation_stream.html.twig', [
                        'message' => $this->translator->trans('messaging.request_sent'),
                    ]);
                }

                $this->addFlash('success', $this->translator->trans('messaging.request_sent'));

                return $this->redirectToRoute('conversations_list');
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof ConversationRequestAlreadyPending) {
                    $this->addFlash('warning', $this->translator->trans('messaging.pending_request_exists'));

                    return $this->redirectToRoute('conversations_list');
                } else {
                    $this->addFlash('danger', $this->translator->trans('messaging.send_failed'));
                }

                return $this->render(
                    $isModal ? 'messaging/start_marketplace_conversation_modal.html.twig' : 'messaging/start_conversation.html.twig',
                    $templateParams,
                );
            }
        }

        return $this->render(
            $isModal ? 'messaging/start_marketplace_conversation_modal.html.twig' : 'messaging/start_conversation.html.twig',
            $templateParams,
        );
    }

    /**
     * Marketplace at events (docs/features/marketplace/11-events.md): "Could you bring … to … (date)?" in the buyer's
     * language - only when the event is a marketplace event the listing's seller is going to, otherwise nothing.
     */
    private function askToBringMessage(
        Request $request,
        PlayerProfile $buyer,
        SellSwapListItem $sellSwapListItem,
        PuzzleOverview $puzzle,
    ): null|string {
        $eventId = $request->query->get('event');
        $sellerId = $sellSwapListItem->player->id->toString();

        if (is_string($eventId) === false || $eventId === '' || $sellerId === $buyer->playerId) {
            return null;
        }

        foreach ($this->getMarketplaceEvents->forPlayer($sellerId) as $event) {
            if ($event->competitionId !== strtolower($eventId)) {
                continue;
            }

            $locale = $buyer->locale ?? $request->getLocale();

            return $this->translator->trans('marketplace_events.chat.prefill', [
                '%puzzle%' => $puzzle->puzzleName,
                '%event%' => $event->reference->displayName(),
                '%date%' => $this->eventDateFormatter->format($event->dateFrom, $event->dateTo, $locale),
            ], locale: $locale);
        }

        return null;
    }
}
