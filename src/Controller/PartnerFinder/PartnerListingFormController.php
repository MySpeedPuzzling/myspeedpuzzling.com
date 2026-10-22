<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\FormData\PartnerListingFormData;
use SpeedPuzzling\Web\FormType\PartnerListingFormType;
use SpeedPuzzling\Web\Message\EditPartnerListing;
use SpeedPuzzling\Web\Message\ListForPartner;
use SpeedPuzzling\Web\Query\GetAllowedPartnerCoMembers;
use SpeedPuzzling\Web\Query\GetPartnerListings;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerListingRules;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "List me" / "Edit listing" (docs/features/partner-finder.md, "Listing yourself"): the modal in `modal-frame` from the
 * finder page, a full page otherwise. One open listing per event - an open one is edited. A refused value comes back as
 * an error on its field (422); a saved one closes the modal and refreshes the page, or redirects to the finder.
 */
final class PartnerListingFormController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CompetitionRepository $competitionRepository,
        readonly private PartnerListingRepository $partnerListingRepository,
        readonly private PartnerListingRules $partnerListingRules,
        readonly private GetPartnerListings $getPartnerListings,
        readonly private GetAllowedPartnerCoMembers $getAllowedPartnerCoMembers,
        readonly private PartnerFinderUrls $partnerFinderUrls,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PartnerFinderNotAvailable
     */
    #[Route(
        path: '/{_locale}/partners/{competitionId}/list-me',
        name: 'partner_listing_form',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $competitionId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $offer = $this->partnerListingRules->offer($competitionId);
        $competition = $this->competitionRepository->get($competitionId);
        $finderUrl = $this->partnerFinderUrls->finder($competition);
        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        $listing = $this->partnerListingRepository->findOpenFor($competitionId, $viewer->playerId);
        $data = $listing !== null
            ? PartnerListingFormData::fromListing($listing)
            : PartnerListingFormData::forNewListing(Uuid::uuid7()->toString(), $offer->kinds, $offer->teamSize, $viewer->countryCode?->name);

        $formAction = $this->generateUrl('partner_listing_form', ['competitionId' => $competitionId]);
        $form = $this->createForm(PartnerListingFormType::class, $data, [
            'offer' => $offer,
            'locale' => $request->getLocale(),
            'action' => $formAction,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->save($form, $data, $listing, $competitionId, $viewer)) {
            $this->addFlash('success', $this->translator->trans($listing !== null ? 'partner_finder.form.flash_saved' : 'partner_finder.form.flash_listed'));

            if ($isModal && $request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('partner_finder/_listing_saved_stream.html.twig');
            }

            return $this->redirect($finderUrl, Response::HTTP_SEE_OTHER);
        }

        $response = $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null;

        // The custom chips other puzzlers use at the event, as suggestions - the page's own statement, nothing extra
        // on the modal, which the finder page opens (it has them already)
        $suggestions = [];

        if ($isModal === false) {
            $suggestions = $this->getPartnerListings->forEvent($competitionId, $viewer->playerId, false)?->customChipSuggestions() ?? [];
        }

        return $this->render($isModal ? 'partner_finder/_listing_form_modal.html.twig' : 'partner_finder/listing_form.html.twig', [
            'form' => $form,
            'form_action' => $formAction,
            'editing' => $listing !== null,
            'offer' => $offer,
            'event_name' => $competition->name,
            'finder_url' => $finderUrl,
            'registration_managed' => $competition->registrationManaged,
            'custom_suggestions' => $suggestions,
            // The co-member picker's picks so far (a saved listing's, or a refused form's) - nothing queried without any
            'co_member_choices' => $this->getAllowedPartnerCoMembers->choices($viewer->playerId, $data->coMemberIdList()),
            'viewer_player_id' => $viewer->playerId,
        ], $response);
    }

    /**
     * @param FormInterface<PartnerListingFormData> $form
     */
    private function save(FormInterface $form, PartnerListingFormData $data, null|PartnerListing $listing, string $competitionId, PlayerProfile $viewer): bool
    {
        try {
            if ($listing !== null) {
                $this->messageBus->dispatch(new EditPartnerListing(
                    listingId: $listing->id->toString(),
                    playerId: $viewer->playerId,
                    groupSize: $data->groupSizeValue(),
                    lookingFor: $data->kinds(),
                    needed: $data->neededValue(),
                    coMemberIds: $data->coMemberIdList(),
                    goal: $data->goal,
                    strengths: $data->strengths,
                    customStrengths: $data->customStrengthList(),
                    languages: $data->languages,
                    note: $data->noteValue(),
                ));

                return true;
            }

            if (Uuid::isValid($data->listingId) === false) {
                $data->listingId = Uuid::uuid7()->toString();
            }

            $this->messageBus->dispatch(new ListForPartner(
                listingId: $data->listingId,
                competitionId: $competitionId,
                playerId: $viewer->playerId,
                groupSize: $data->groupSizeValue(),
                lookingFor: $data->kinds(),
                needed: $data->neededValue(),
                coMemberIds: $data->coMemberIdList(),
                goal: $data->goal,
                strengths: $data->strengths,
                customStrengths: $data->customStrengthList(),
                languages: $data->languages,
                note: $data->noteValue(),
            ));

            return true;
        } catch (PartnerListingInvalid $invalid) {
            // Comes unwrapped (UnwrapHttpExceptionMiddleware); the handler checked it before changing anything
            $field = $form->has($invalid->field) ? $form->get($invalid->field) : $form;
            $field->addError(new FormError($invalid->trans($this->translator)));

            $this->logger->info('Partner finder listing refused', ['exception' => $invalid]);

            return false;
        }
    }
}
