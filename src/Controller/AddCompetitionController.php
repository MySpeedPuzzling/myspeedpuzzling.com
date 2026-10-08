<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Add an event - or a series, when "Recurring" is ticked. docs/features/organizations/README.md "Forms": under an
 * organization the player is on the team of (`?organization=` pre-selects it; admins choose any), "Who can enter",
 * "When it happens" (series), and "Save as draft" next to the normal submit. Created under an approved organization by
 * its team, it needs no admin approval (OrganizationApprovalPolicy in the handlers); an admin's own item does not
 * e-mail the admins.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-udalost',
            'en' => '/en/add-event',
            'es' => '/es/add-event',
            'ja' => '/ja/add-event',
            'fr' => '/fr/add-event',
            'de' => '/de/add-event',
        ],
        name: 'add_competition',
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('events');
        }

        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);
        $organizationChoices = $this->organizationSelectChoices->forPlayer($player->playerId, $isAdmin);

        $formData = new CompetitionFormData();
        $formData->organizationId = OrganizationSelectChoices::pick($organizationChoices, $request->query->getString('organization'));

        $form = $this->createForm(CompetitionFormType::class, $formData, [
            // Nothing to choose from: no select
            'organization_choices' => $organizationChoices !== [] ? $organizationChoices : null,
            'schedule_field' => true,
            'draft_button' => true,
        ]);
        // A logo chosen for a refused submit comes back (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $isDraft = self::draftClicked($form);

            try {
                if ($data->isRecurring) {
                    $seriesId = Uuid::uuid7();
                    $isOnline = $data->isOnline === true;

                    $this->messageBus->dispatch(new AddCompetitionSeries(
                        seriesId: $seriesId,
                        playerId: $player->playerId,
                        name: $data->name ?? '',
                        shortcut: $data->shortcut,
                        description: $data->description,
                        link: $data->link,
                        isOnline: $isOnline,
                        location: $isOnline ? null : $data->location,
                        locationCountryCode: $isOnline ? null : $data->locationCountryCode,
                        logo: $data->logo,
                        maintainerIds: $data->maintainers,
                        organizationId: $data->organizationId,
                        eligibility: $data->eligibility,
                        schedule: $data->schedule,
                        isDraft: $isDraft,
                        notifyAdmin: $isAdmin === false,
                    ));

                    $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
                    $this->addFlash('success', $this->createdMessage($isDraft, $this->competitionSeriesRepository->get($seriesId->toString())->approvedAt !== null));

                    return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId->toString()]);
                }

                $competitionId = Uuid::uuid7();

                $this->messageBus->dispatch(new AddCompetition(
                    competitionId: $competitionId,
                    playerId: $player->playerId,
                    name: $data->name ?? '',
                    shortcut: $data->shortcut,
                    description: $data->description,
                    link: $data->link,
                    registrationLink: $data->registrationLink,
                    resultsLink: $data->resultsLink,
                    location: $data->isOnline === true ? null : $data->location,
                    locationCountryCode: $data->locationCountryCode,
                    // An online event keeps its dates too - optional for it, an ongoing one leaves them empty
                    dateFrom: $data->dateFrom,
                    dateTo: $data->dateTo,
                    isOnline: $data->isOnline === true,
                    logo: $data->logo,
                    maintainerIds: $data->maintainers,
                    notifyAdmin: $isAdmin === false,
                    organizationId: $data->organizationId,
                    eligibility: $data->eligibility,
                    isDraft: $isDraft,
                ));

                $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
                $this->addFlash('success', $this->createdMessage($isDraft, $this->competitionRepository->get($competitionId->toString())->approvedAt !== null));

                return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId->toString()]);
            } catch (OrganizationNotManaged) {
                // Left the organization's team since the form was opened
                $field = $form->has('organizationId') ? $form->get('organizationId') : $form;
                $field->addError(new FormError($this->translator->trans('organizer_tools.form.organization_not_managed')));
            }
        }

        return $this->render('add_competition.html.twig', [
            'form' => $form,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $player->playerId),
        ]);
    }

    /**
     * @param FormInterface<CompetitionFormData> $form
     */
    private static function draftClicked(FormInterface $form): bool
    {
        if ($form->has('saveDraft') === false) {
            return false;
        }

        $button = $form->get('saveDraft');

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    private function createdMessage(bool $isDraft, bool $approved): string
    {
        if ($isDraft) {
            return $this->translator->trans('organizer_tools.flash.saved_as_draft');
        }

        return $this->translator->trans($approved ? 'organizer_tools.flash.created_published' : 'competition.flash.created');
    }
}
