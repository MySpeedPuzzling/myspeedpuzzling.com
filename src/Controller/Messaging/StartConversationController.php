<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Exceptions\ConversationRequestAlreadyPending;
use SpeedPuzzling\Web\Exceptions\DirectMessagesDisabled;
use SpeedPuzzling\Web\Exceptions\UserIsBlocked;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StartConversationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/messages/new/{recipientId}',
        name: 'start_conversation',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $recipientId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $recipient = $this->getPlayerProfile->byId($recipientId);

        if ($request->isMethod('POST')) {
            $messageContent = trim($request->request->getString('message'));

            if ($messageContent === '') {
                $this->addFlash('danger', $this->translator->trans('messaging.message_empty'));

                return $this->render('messaging/start_conversation.html.twig', [
                    'recipient' => $recipient,
                ]);
            }

            try {
                $this->messageBus->dispatch(new StartConversation(
                    initiatorId: $loggedPlayer->playerId,
                    recipientId: $recipientId,
                    initialMessage: $messageContent,
                ));

                $this->addFlash('success', $this->translator->trans('messaging.request_sent'));

                return $this->redirectToRoute('conversations_list');
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof UserIsBlocked) {
                    $this->addFlash('danger', $this->translator->trans('messaging.cannot_message_user'));
                } elseif ($realException instanceof DirectMessagesDisabled) {
                    $this->addFlash('warning', $this->translator->trans('messaging.direct_messages_disabled'));
                } elseif ($realException instanceof ConversationRequestAlreadyPending) {
                    $this->addFlash('warning', $this->translator->trans('messaging.pending_request_exists'));

                    return $this->redirectToRoute('conversations_list');
                }
            }
        }

        return $this->render('messaging/start_conversation.html.twig', [
            'recipient' => $recipient,
        ]);
    }
}
