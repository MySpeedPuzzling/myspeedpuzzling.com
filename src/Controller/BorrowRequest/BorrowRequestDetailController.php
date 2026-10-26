<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\BorrowRequest;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\AnswerBorrowRequest;
use SpeedPuzzling\Web\Query\GetBorrowRequests;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One borrow request (docs/features/wishlist-availability.md) - the e-mail's "Answer" and the notifications land here.
 * The owner answers per puzzle (POST, stateless CSRF `borrow_request`); GET only shows - mail scanners open links. The
 * asker sees where it stands, withdraws, and records the hand-over. Anybody else gets a 404.
 *
 * After answering with a yes, or "Reply in chat", the owner is taken into the chat with the asker.
 */
final class BorrowRequestDetailController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetBorrowRequests $getBorrowRequests,
        readonly private BorrowRequestRepository $borrowRequestRepository,
        readonly private ConversationRepository $conversationRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/borrow-requests/{requestId}',
        name: 'borrow_request_detail',
        requirements: ['requestId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $requestId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $overview = $this->getBorrowRequests->forViewer($requestId, $viewer->playerId);

        if ($overview === null) {
            throw new BorrowRequestNotFound();
        }

        $isOwner = $overview->ownerId === $viewer->playerId;

        if ($request->isMethod('POST')) {
            if ($isOwner === false) {
                throw new BorrowRequestNotFound();
            }

            return $this->answer($request, $requestId, $viewer->playerId);
        }

        $response = $this->render('borrow_requests/detail.html.twig', [
            'request_overview' => $overview,
            'is_owner' => $isOwner,
            'csrf_token_id' => SendBorrowRequestsController::CSRF_TOKEN_ID,
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function answer(Request $request, string $requestId, string $ownerId): Response
    {
        if ($this->isCsrfTokenValid(SendBorrowRequestsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('borrow_request.flash.expired'));

            return $this->redirectToRoute('borrow_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        $answers = [];

        foreach ($request->request->all('answers') as $itemId => $answer) {
            if (is_string($answer)) {
                $answers[(string) $itemId] = $answer;
            }
        }

        $remove = array_values(array_filter($request->request->all('removeFromCollection'), is_string(...)));
        $openChat = $request->request->getString('chat') === '1';

        try {
            $this->messageBus->dispatch(new AnswerBorrowRequest(
                requestId: $requestId,
                playerId: $ownerId,
                answers: $answers,
                removeFromCollection: $remove,
                openChat: $openChat,
                locale: $request->getLocale(),
            ));
        } catch (HandlerFailedException $exception) {
            // Only the expected refusal is answered here - anything else is a bug and bubbles up (500, Sentry)
            if ($exception->getWrappedExceptions(MessagingMuted::class, true) === []) {
                throw $exception;
            }

            $this->addFlash('danger', $this->translator->trans('borrow_request.flash.chat_muted'));

            return $this->redirectToRoute('borrow_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        $borrowRequest = $this->borrowRequestRepository->get($requestId);
        $conversation = $borrowRequest->isReplied()
            ? $this->conversationRepository->findAcceptedBetween($borrowRequest->owner, $borrowRequest->asker)
            : null;

        if ($conversation !== null && ($openChat || in_array('yes', $answers, true))) {
            $this->addFlash('success', $this->translator->trans('borrow_request.flash.answered_chat'));

            return $this->redirectToRoute('conversation_detail', ['conversationId' => $conversation->id->toString()], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', $this->translator->trans('borrow_request.flash.answered'));

        return $this->redirectToRoute('borrow_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }
}
