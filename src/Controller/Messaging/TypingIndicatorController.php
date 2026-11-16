<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TypingIndicatorController extends AbstractController
{
    public function __construct(
        readonly private ConversationRepository $conversationRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private HubInterface $hub,
        readonly private MessageBusInterface $messageBus,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/messages/{conversationId}/typing',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        name: 'typing_indicator',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $conversationId, Request $request): JsonResponse
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

        // Fail-open: a hub that is down or restarting only costs the "typing…" hint. Info, not warning - typing pings are
        // frequent, so an outage would flood Sentry (MercureNotifier reports the same outage once per real message)
        try {
            $this->hub->publish(new Update(
                '/conversation/' . $conversationId . '/typing',
                json_encode([
                    'type' => 'typing',
                    'playerId' => $loggedPlayer->playerId,
                    'isTyping' => true,
                ], JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->info('Could not publish a typing indicator', ['exception' => $exception]);
        }

        if ($request->headers->has('X-Mark-As-Read')) {
            $this->messageBus->dispatch(new MarkMessagesAsRead(
                conversationId: $conversationId,
                playerId: $loggedPlayer->playerId,
            ));
        }

        return new JsonResponse(['ok' => true]);
    }
}
