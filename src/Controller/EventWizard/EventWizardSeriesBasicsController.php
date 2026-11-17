<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\EventWizardSeriesBasicsFormType;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEventWizardSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSeriesAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventTypes;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Basics" of a regular series (W-creation-wizard-research.md §3.3, W3): W1's Basics
 * without the date and time - "When" is the next step's rule. The country is kept for an online series too: it sets
 * the zone of every date's round.
 *
 * - New series (`event_wizard_series_basics`): the first save creates it as a draft (AddCompetitionSeries, isDraft) -
 *   the admins hear of it when it is published.
 * - A draft series again (`event_wizard_series_basics_edit`): EditCompetitionSeries, everything not asked here kept.
 *   A published series goes to its management page.
 *
 * A full-page form: saved → 303 (Dates, or the series' page for "Finish later"), refused → 422.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardSeriesBasicsController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSeriesAccess $seriesAccess,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
        private readonly OrganizationRepository $organizationRepository,
        private readonly CompetitionSeriesRepository $seriesRepository,
        private readonly GetEventWizardSeries $getEventWizardSeries,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/series/basics',
        name: 'event_wizard_series_basics',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET', 'POST'],
    )]
    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}/basics',
        name: 'event_wizard_series_basics_edit',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, null|string $seriesId = null): Response
    {
        $player = $this->access->player();

        if ($seriesId !== null) {
            $progress = $this->seriesAccess->draft($seriesId, EventWizardStep::SeriesBasics);

            if ($progress === null) {
                return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
            }

            $series = $this->seriesRepository->get($seriesId);
            $formData = self::formData($series);
            $organizationName = $series->organization?->name;
        } else {
            $progress = $this->eventWizardSteps->forNewEvent(EventWizardPath::Series);
            $series = null;
            $organizationChoices = $this->organizationSelectChoices->forPlayer($player->playerId, $this->isGranted(AdminAccessVoter::ADMIN_ACCESS));
            $formData = new CompetitionFormData();
            $formData->isOnline = false;
            $formData->isRecurring = true;
            $formData->organizationId = OrganizationSelectChoices::pick($organizationChoices, $request->query->getString('organization'));
            $organizationName = null;
            foreach ($organizationChoices as $choice) {
                if ($choice->id === $formData->organizationId) {
                    $organizationName = $choice->name;
                }
            }
            // The organization's country, else the player's
            $organizationCountry = $formData->organizationId !== null ? $this->organizationRepository->get($formData->organizationId)->countryCode : null;
            $formData->locationCountryCode = CountryCode::fromCode($organizationCountry)->name ?? CountryCode::fromCode($player->country)?->name;
        }

        $form = $this->createForm(EventWizardSeriesBasicsFormType::class, $formData, [
            'action' => $request->getRequestUri(),
        ]);

        // A new series' id travels with the page (kept on a refused submit): a second submit creates nothing twice
        if ($series === null) {
            $form->get('draftId')->setData(Uuid::uuid7()->toString());
        }

        // A logo chosen for a refused submit comes back (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $isOnline = $data->isOnline === true;

            try {
                if ($series === null) {
                    $draftId = $form->get('draftId')->getData();
                    $newId = is_string($draftId) && Uuid::isValid($draftId) ? strtolower($draftId) : Uuid::uuid7()->toString();
                    $existing = $this->existingDraft($newId, $player->playerId);

                    // An id taken by anything but this player's draft series: a fresh one
                    if ($existing === false) {
                        $newId = Uuid::uuid7()->toString();
                    }

                    // The same page sent again (a double click, a resend) finds the series its first submit created
                    if ($existing !== true) {
                        $this->addSeries($newId, $player->playerId, $data);
                    }

                    $seriesId = $newId;
                } else {
                    $this->messageBus->dispatch(new EditCompetitionSeries(
                        seriesId: $series->id->toString(),
                        name: $data->name ?? '',
                        shortcut: $data->shortcut,
                        description: $data->description,
                        link: $data->link,
                        isOnline: $isOnline,
                        location: $isOnline ? null : $data->location,
                        locationCountryCode: $data->locationCountryCode,
                        logo: $data->logo,
                        maintainerIds: $data->maintainers,
                        eligibility: $data->eligibility,
                        // Not asked here - kept
                        schedule: $series->schedule,
                        eventTypes: EventTypes::of($data->eventTypes),
                    ));
                    $seriesId = $series->id->toString();
                }
            } catch (HandlerFailedException | OrganizationNotManaged $failure) {
                if (EventWizardRefusal::find($failure, OrganizationNotManaged::class) === null) {
                    throw $failure;
                }

                // Left the organization's team since the entry screen
                $form->addError(new FormError($this->translator->trans('organizer_tools.form.organization_not_managed')));

                return $this->page($form, $progress, $restoredPhotos, $player->playerId, $organizationName, $series?->logo);
            }

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);

            $later = $form->get('later');
            if ($later instanceof ClickableInterface && $later->isClicked()) {
                $this->addFlash('success', $this->translator->trans('event_wizard.flash.saved_for_later'));

                $slug = $this->seriesRepository->get($seriesId)->slug;

                return $slug !== null
                    ? $this->redirectToRoute('competition_series_detail', ['slug' => $slug], Response::HTTP_SEE_OTHER)
                    : $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
            }

            return $this->redirectToRoute(EventWizardStep::Dates->route(), ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, $progress, $restoredPhotos, $player->playerId, $organizationName, $series?->logo);
    }

    private function addSeries(string $seriesId, string $playerId, CompetitionFormData $data): void
    {
        $isOnline = $data->isOnline === true;

        $this->messageBus->dispatch(new AddCompetitionSeries(
            seriesId: Uuid::fromString($seriesId),
            playerId: $playerId,
            name: $data->name ?? '',
            shortcut: $data->shortcut,
            description: $data->description,
            link: $data->link,
            isOnline: $isOnline,
            location: $isOnline ? null : $data->location,
            // Kept for an online series too - it sets the zone of the dates' rounds
            locationCountryCode: $data->locationCountryCode,
            logo: $data->logo,
            maintainerIds: $data->maintainers,
            organizationId: $data->organizationId,
            eligibility: $data->eligibility,
            eventTypes: EventTypes::of($data->eventTypes),
            isDraft: true,
            notifyAdmin: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false,
        ));
    }

    /**
     * Whether a new series' id is taken: null = free, true = the player's own draft (a second submit of the same page),
     * false = anything else
     */
    private function existingDraft(string $seriesId, string $playerId): null|bool
    {
        try {
            $state = $this->getEventWizardSeries->state($seriesId);
        } catch (CompetitionSeriesNotFound) {
            return null;
        }

        return $state->isDraft && $state->addedByPlayerId === strtolower($playerId);
    }

    private static function formData(CompetitionSeries $series): CompetitionFormData
    {
        $formData = new CompetitionFormData();
        $formData->name = $series->name;
        $formData->shortcut = $series->shortcut;
        $formData->description = $series->description;
        $formData->link = $series->link;
        $formData->isOnline = $series->isOnline;
        $formData->location = $series->location;
        $formData->locationCountryCode = $series->locationCountryCode;
        $formData->maintainers = array_values(array_map(static fn ($maintainer): string => $maintainer->id->toString(), $series->maintainers->toArray()));
        $formData->eligibility = $series->eligibility;
        $formData->eventTypes = $series->eventTypes()->types ?? [];
        $formData->organizationId = $series->organization?->id->toString();
        // A series: no dates asked, the place optional
        $formData->isRecurring = true;

        return $formData;
    }

    /**
     * @param FormInterface<CompetitionFormData> $form
     * @param array<string, null|string> $restoredPhotos
     */
    private function page(FormInterface $form, EventWizardProgress $progress, array $restoredPhotos, string $playerId, null|string $organizationName, null|string $currentLogo): Response
    {
        return $this->render('event_wizard/series_basics.html.twig', [
            'form' => $form,
            'progress' => $progress,
            'step' => EventWizardStep::SeriesBasics,
            'organization_name' => $organizationName,
            'current_logo' => $currentLogo,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $playerId),
        ]);
    }
}
