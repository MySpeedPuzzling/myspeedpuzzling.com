<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\ClosePartnerListing;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Found my partner" / "Remove listing" on the own row of the finder (docs/features/partner-finder.md D7): a
 * stateless-CSRF POST, then back to the finder (or the validated `return`) with a flash. Somebody else's listing is a 404.
 */
final class ClosePartnerListingController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'partner_listing';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PartnerListingRepository $partnerListingRepository,
        readonly private PartnerFinderUrls $partnerFinderUrls,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/partners/listing/{listingId}/close',
        name: 'partner_listing_close',
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

        if ($listing->player->id->toString() !== $viewer->playerId) {
            throw new PartnerListingNotFound();
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));
        $back = $returnUrl !== null
            ? $this->redirect($returnUrl->path, Response::HTTP_SEE_OTHER)
            : $this->redirect($this->partnerFinderUrls->finder($listing->competition), Response::HTTP_SEE_OTHER);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('partner_finder.listing.flash_expired'));

            return $back;
        }

        $reason = $request->request->getString('reason') === PartnerListingClosedReason::Found->value
            ? PartnerListingClosedReason::Found
            : PartnerListingClosedReason::Withdrawn;
        $countsDown = $reason === PartnerListingClosedReason::Found && $listing->needed !== null && $listing->needed > 1;

        $this->messageBus->dispatch(new ClosePartnerListing(
            listingId: $listingId,
            playerId: $viewer->playerId,
            reason: $reason,
            teamedUpWith: $reason === PartnerListingClosedReason::Found
                ? array_values(array_filter($request->request->all('teamedUpWith'), is_string(...)))
                : [],
        ));

        $this->addFlash('success', $this->translator->trans(match (true) {
            $countsDown => 'partner_finder.listing.flash_counted_down',
            $reason === PartnerListingClosedReason::Found => 'partner_finder.listing.flash_found',
            default => 'partner_finder.listing.flash_removed',
        }));

        return $back;
    }
}
