<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
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

/**
 * "I'd like to puzzle with you" on a finder row (docs/features/partner-finder.md, D6): a stateless-CSRF POST that always
 * answers the same way, so a blocked sender cannot tell - the row's Turbo Frame comes back from the page the redirect
 * leads to, showing "Sent".
 */
final class SendPartnerRequestController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'partner_request';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PartnerListingRepository $partnerListingRepository,
        readonly private MessageBusInterface $messageBus,
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
            return $back;
        }

        $kind = PartnerKind::tryFrom($request->request->getString('kind')) ?? $listing->lookingFor()[0];
        $note = mb_substr(trim($request->request->getString('note')), 0, 200);

        $this->messageBus->dispatch(new SendPartnerRequest(
            requestId: Uuid::uuid7()->toString(),
            listingId: $listingId,
            competitionId: $listing->competition->id->toString(),
            fromPlayerId: $viewer->playerId,
            kind: $kind,
            note: $note === '' ? null : $note,
        ));

        return $back;
    }
}
