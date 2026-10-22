<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;
use SpeedPuzzling\Web\Message\ReplyToPartnerRequest;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
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
 * The listed puzzler's answer to an "I'd like to puzzle with you" (docs/features/partner-finder.md D6). GET shows the
 * request with a Reply button - the e-mail's "Reply to …" lands here, and mail scanners open links, so GET never replies.
 * POST (stateless CSRF `partner_request`, the notification's Reply button too) opens the chat with a pre-filled first
 * line and leads into it. Only the listing's owner sees or answers it; anybody else gets a 404.
 */
final class ReplyToPartnerRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PartnerRequestRepository $partnerRequestRepository,
        readonly private ConversationRepository $conversationRepository,
        readonly private PartnerFinderUrls $partnerFinderUrls,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/partners/request/{requestId}/reply',
        name: 'partner_request_reply',
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

        $partnerRequest = $this->partnerRequestRepository->get($requestId);
        $listing = $partnerRequest->listing;

        if ($listing->player->id->toString() !== $viewer->playerId) {
            throw new PartnerRequestNotFound();
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(SendPartnerRequestController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                return $this->redirectToRoute('partner_request_reply', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
            }

            try {
                $this->messageBus->dispatch(new ReplyToPartnerRequest($requestId, $viewer->playerId, $request->getLocale()));
            } catch (HandlerFailedException) {
                // Messaging muted, mostly - the page says it could not open the chat
                $this->addFlash('danger', $this->translator->trans('partner_finder.request.flash.reply_failed'));

                return $this->redirectToRoute('partner_request_reply', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
            }

            $conversation = $this->conversationRepository->findAcceptedBetween($listing->player, $partnerRequest->fromPlayer);

            return $conversation !== null
                ? $this->redirectToRoute('conversation_detail', ['conversationId' => $conversation->id->toString()], Response::HTTP_SEE_OTHER)
                : $this->redirectToRoute('conversations_list', status: Response::HTTP_SEE_OTHER);
        }

        $sender = $partnerRequest->fromPlayer;
        $response = $this->render('partner_finder/request_reply.html.twig', [
            'partner_request' => $partnerRequest,
            'sender' => $sender,
            'event_name' => $listing->competition->name,
            'finder_url' => $this->partnerFinderUrls->forCompetition($listing->competition),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
