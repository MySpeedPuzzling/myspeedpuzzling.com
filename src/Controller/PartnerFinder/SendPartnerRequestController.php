<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\PartnerRequest;
use SpeedPuzzling\Web\Exceptions\PartnerRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotPossible;
use SpeedPuzzling\Web\Message\SendPartnerRequest;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "I'd like to puzzle with you" on a finder row (docs/features/partner-finder.md, D6): a stateless-CSRF POST that always
 * answers the same way - a 303 back to `return` - so a blocked sender cannot tell: a block reads exactly like a sent
 * request ("Sent" flash). Only what is about the sender himself is said differently: the daily limit, and a listing
 * that cannot be asked any more (closed meanwhile, his own, a kind it does not look for).
 *
 * A form inside a Turbo Frame follows the redirect within the frame, so the row's frame comes back from the page the
 * redirect leads to, showing "Sent".
 */
final class SendPartnerRequestController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'partner_request';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PartnerListingRepository $partnerListingRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/partners/listing/{listingId}/request',
        name: 'partner_request_send',
        requirements: ['listingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $listingId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $listing = $this->partnerListingRepository->get($listingId);
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));
        $back = $returnUrl !== null
            ? $this->redirect($returnUrl->path, Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('events', status: Response::HTTP_SEE_OTHER);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('partner_finder.listing.flash_expired'));

            return $back;
        }

        $kind = PartnerKind::tryFrom($request->request->getString('kind')) ?? $listing->lookingFor()[0];
        $note = mb_substr(trim($request->request->getString('note')), 0, PartnerRequest::NOTE_MAX_LENGTH);

        try {
            $this->messageBus->dispatch(new SendPartnerRequest(
                requestId: Uuid::uuid7()->toString(),
                listingId: $listingId,
                competitionId: $listing->competition->id->toString(),
                fromPlayerId: $viewer->playerId,
                kind: $kind,
                note: $note === '' ? null : $note,
            ));
        } catch (PartnerRequestLimitReached) {
            $this->addFlash('warning', $this->translator->trans('partner_finder.request.flash.limit_reached'));

            return $back;
        } catch (PartnerRequestNotPossible) {
            $this->addFlash('warning', $this->translator->trans('partner_finder.request.flash.not_possible'));

            return $back;
        }

        // Also when nothing was sent because of a block - the sender must not be able to tell
        $this->addFlash('success', $this->translator->trans('partner_finder.request.flash.sent'));

        return $back;
    }
}
