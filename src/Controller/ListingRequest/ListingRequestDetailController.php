<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ListingRequest;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\AnswerListingRequest;
use SpeedPuzzling\Web\Query\GetListingRequests;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ListingRequestAnswerOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One listing request (docs/features/marketplace/12-listing-requests.md): the seller answers per listing, the buyer sees
 * where it stands and withdraws. GET only shows - mail clients open the e-mail's link; answering is the seller's POST.
 * Anybody but the buyer or the seller gets a 404.
 */
final class ListingRequestDetailController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetListingRequests $getListingRequests,
        readonly private ListingRequestRepository $listingRequestRepository,
        readonly private ConversationRepository $conversationRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/sell-swap-requests/{requestId}',
        name: 'listing_request_detail',
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

        $overview = $this->getListingRequests->forViewer($requestId, $viewer->playerId);

        if ($overview === null) {
            throw new ListingRequestNotFound();
        }

        $isSeller = $overview->sellerId === $viewer->playerId;

        if ($request->isMethod('POST')) {
            if ($isSeller === false) {
                throw new ListingRequestNotFound();
            }

            return $this->answer($request, $requestId, $viewer->playerId);
        }

        $response = $this->render('listing_requests/detail.html.twig', [
            'request_overview' => $overview,
            'is_seller' => $isSeller,
            'csrf_token_id' => SendListingRequestsController::CSRF_TOKEN_ID,
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function answer(Request $request, string $requestId, string $sellerId): Response
    {
        if ($this->isCsrfTokenValid(SendListingRequestsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('listing_request.flash.expired'));

            return $this->redirectToRoute('listing_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        $answers = [];

        foreach ($request->request->all('answers') as $itemId => $answer) {
            if (is_string($answer)) {
                $answers[(string) $itemId] = $answer;
            }
        }

        $remove = array_values(array_filter($request->request->all('removeFromList'), is_string(...)));
        $openChat = $request->request->getString('chat') === '1';

        try {
            $envelope = $this->messageBus->dispatch(new AnswerListingRequest(
                requestId: $requestId,
                playerId: $sellerId,
                answers: $answers,
                removeFromList: $remove,
                openChat: $openChat,
                locale: $request->getLocale(),
            ));
        } catch (HandlerFailedException $exception) {
            // Only the expected refusal is answered here - anything else is a bug and bubbles up (500, Sentry)
            if ($exception->getWrappedExceptions(MessagingMuted::class, true) === []) {
                throw $exception;
            }

            $this->addFlash('danger', $this->translator->trans('listing_request.flash.chat_muted'));

            return $this->redirectToRoute('listing_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        /** @var ListingRequestAnswerOutcome $outcome */
        $outcome = $envelope->last(HandledStamp::class)?->getResult();

        if ($outcome->reservedForSomebodyElse > 0) {
            $this->addFlash('warning', $this->translator->trans('listing_request.flash.reserved_for_somebody_else', ['%count%' => $outcome->reservedForSomebodyElse]));
        }

        $listingRequest = $this->listingRequestRepository->get($requestId);
        $conversation = $listingRequest->isReplied()
            ? $this->conversationRepository->findAcceptedBetween($listingRequest->seller, $listingRequest->buyer)
            : null;

        if ($conversation !== null && ($openChat || in_array('yes', $answers, true))) {
            $this->addFlash('success', $this->translator->trans('listing_request.flash.answered_chat'));

            return $this->redirectToRoute('conversation_detail', ['conversationId' => $conversation->id->toString()], Response::HTTP_SEE_OTHER);
        }

        if ($outcome->changed > 0) {
            $this->addFlash('success', $this->translator->trans('listing_request.flash.answered'));
        }

        return $this->redirectToRoute('listing_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }
}
