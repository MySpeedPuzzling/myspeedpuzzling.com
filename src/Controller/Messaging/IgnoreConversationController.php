<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\IgnoreConversation;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IgnoreConversationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/messages/{conversationId}/ignore',
        name: 'ignore_conversation',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $conversationId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        try {
            $this->messageBus->dispatch(new IgnoreConversation(
                conversationId: $conversationId,
                playerId: $loggedPlayer->playerId,
            ));
        } catch (ConversationNotFound) {
            // Already gone — ignoring it has effectively happened anyway.
            $this->addFlash('warning', $this->translator->trans('messaging.conversation_unavailable'));

            return $this->redirectToRoute('conversations_list', ['tab' => 'requests']);
        }

        $this->addFlash('success', $this->translator->trans('messaging.request_ignored'));

        return $this->redirectToRoute('conversations_list', ['tab' => 'requests']);
    }
}
