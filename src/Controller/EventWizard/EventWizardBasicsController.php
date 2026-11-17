<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\EventWizardBasicsFormType;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\SaveEventWizardBasics;
use SpeedPuzzling\Web\Message\StartEventWizardDraft;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Results\OrganizationChoice;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventTypes;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
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
 * The event creation wizard's "Basics" (W-creation-wizard-research.md §3.3): name, in person / online, place and
 * country, date, start time, "it lasts more days"; the rest under "More details".
 *
 * - New event (`event_wizard_basics`): the first save creates the draft and, on the one-round path, its single round
 *   (StartEventWizardDraft) - start = the date + start time in the zone of the event's country (RoundTimezone), name =
 *   its category, the default time limit, reveal delay and an automatic colour (EventWizardDefaults).
 * - A draft again (`event_wizard_basics_edit`): EditCompetition, and the single round's start follows the date and the
 *   time (SaveEventWizardBasics) - a draft without a round gets it now. Only for a draft on a path with wizard steps -
 *   a published event (or an edition, or several rounds until W3/W5) goes to its edit page.
 *
 * A full-page form: a saved step answers 303 (to the next step, or the draft's page for "Finish later"), a refused one
 * 422.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardBasicsController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
        private readonly OrganizationRepository $organizationRepository,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly EventWizardDefaults $defaults,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/basics',
        name: 'event_wizard_basics',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET', 'POST'],
    )]
    #[Route(
        path: '/{_locale}/new-event/{competitionId}/basics',
        name: 'event_wizard_basics_edit',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, null|string $competitionId = null): Response
    {
        $player = $this->access->player();

        if ($competitionId !== null) {
            $progress = $this->access->draft($competitionId, EventWizardStep::Basics);

            if ($progress === null) {
                return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
            }

            $competition = $this->competitionRepository->get($competitionId);
            $round = $progress->state?->singleRoundId !== null ? $this->competitionRoundRepository->get($progress->state->singleRoundId) : null;
            $formData = CompetitionFormData::fromCompetition($competition);
            $organizationName = $competition->organization?->name;
        } else {
            $path = EventWizardPath::tryFrom($request->query->getString('path')) ?? EventWizardPath::OneRound;
            $progress = $this->eventWizardSteps->forNewEvent($path);

            if ($progress->has(EventWizardStep::Basics) === false) {
                return $this->redirectToRoute('event_wizard');
            }

            $competition = null;
            $round = null;
            $organizationChoices = $this->organizationSelectChoices->forPlayer($player->playerId, $this->isGranted(AdminAccessVoter::ADMIN_ACCESS));
            $formData = new CompetitionFormData();
            $formData->isOnline = false;
            $formData->organizationId = OrganizationSelectChoices::pick($organizationChoices, $request->query->getString('organization'));
            $organizationName = self::organizationName($organizationChoices, $formData->organizationId);
            // The organization's country, else the player's
            $organizationCountry = $formData->organizationId !== null ? $this->organizationRepository->get($formData->organizationId)->countryCode : null;
            $formData->locationCountryCode = CountryCode::fromCode($organizationCountry)->name ?? CountryCode::fromCode($player->country)?->name;
        }

        $form = $this->createForm(EventWizardBasicsFormType::class, $formData, [
            'action' => $request->getRequestUri(),
        ]);

        if ($round !== null) {
            $form->get('startTime')->setData(RoundTimezone::toLocal($round->startsAt, $round->displayTimezone())->format('H:i'));
        }
        $form->get('multiDay')->setData($formData->dateFrom !== null && $formData->dateTo !== null && $formData->dateFrom->format('Y-m-d') !== $formData->dateTo->format('Y-m-d'));

        // A logo chosen for a refused submit comes back (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $typedStartTime = $form->get('startTime')->getData();
            $startTime = is_string($typedStartTime) ? EventWizardBasicsFormType::startTime($typedStartTime) : null;
            assert($data->dateFrom !== null && $startTime !== null);
            $countryCode = $data->locationCountryCode;

            // The round's zone: the event's country's - unless the organiser chose another on the round's own page
            $timezone = RoundTimezone::resolve(null, $countryCode);
            if ($round !== null && $round->timezone !== null && $round->timezone !== RoundTimezone::resolve(null, $competition->locationCountryCode)) {
                $timezone = $round->timezone;
            }

            try {
                $startsAt = RoundTimezone::toInstant($data->dateFrom->format('Y-m-d') . ' ' . $startTime, $timezone);
            } catch (InvalidLocalTime) {
                // A time skipped or repeated by a daylight-saving change - say so instead of guessing
                $form->get('startTime')->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));

                return $this->page($form, $progress, $restoredPhotos, $player->playerId, $organizationName, $competition?->logo);
            }

            try {
                if ($competition === null) {
                    $newId = Uuid::uuid7();
                    $this->messageBus->dispatch(new StartEventWizardDraft(
                        competition: $this->addCompetition($newId->toString(), $player->playerId, $data),
                        round: $this->newRound($newId->toString(), $startsAt, $timezone),
                    ));
                    $competitionId = $newId->toString();
                } else {
                    $this->messageBus->dispatch(new SaveEventWizardBasics(
                        competitionId: $competitionId,
                        competition: $this->editCompetition($competition, $data),
                        roundEdit: $round !== null ? self::roundStart($round, $competitionId, $startsAt, $timezone) : null,
                        newRound: $round === null ? $this->newRound($competitionId, $startsAt, $timezone) : null,
                    ));
                }
            } catch (HandlerFailedException | SecretPuzzlesWouldBeRevealed | OnlineTimingInUse | OrganizationNotManaged $failure) {
                if ($this->explain($failure, $form) === false) {
                    throw $failure;
                }

                return $this->page($form, $progress, $restoredPhotos, $player->playerId, $organizationName, $competition?->logo);
            }

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);

            if (self::clicked($form, 'later')) {
                $this->addFlash('success', $this->translator->trans('event_wizard.flash.saved_for_later'));

                return $this->redirect($this->competitionDetailUrl->of($competitionId), Response::HTTP_SEE_OTHER);
            }

            $next = $progress->next(EventWizardStep::Basics) ?? EventWizardStep::Review;

            return $this->redirectToRoute($next->route(), ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, $progress, $restoredPhotos, $player->playerId, $organizationName, $competition?->logo);
    }

    /**
     * @param FormInterface<CompetitionFormData> $form
     * @param array<string, null|string> $restoredPhotos
     */
    private function page(FormInterface $form, EventWizardProgress $progress, array $restoredPhotos, string $playerId, null|string $organizationName, null|string $currentLogo): Response
    {
        return $this->render('event_wizard/basics.html.twig', [
            'form' => $form,
            'progress' => $progress,
            'step' => EventWizardStep::Basics,
            'organization_name' => $organizationName,
            'current_logo' => $currentLogo,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $playerId),
        ]);
    }

    private function addCompetition(string $competitionId, string $playerId, CompetitionFormData $data): AddCompetition
    {
        return new AddCompetition(
            competitionId: Uuid::fromString($competitionId),
            playerId: $playerId,
            name: $data->name ?? '',
            shortcut: $data->shortcut,
            description: $data->description,
            link: $data->link,
            registrationLink: null,
            resultsLink: null,
            location: $data->isOnline === true ? null : $data->location,
            locationCountryCode: $data->locationCountryCode,
            dateFrom: $data->dateFrom,
            dateTo: $data->dateTo,
            isOnline: $data->isOnline === true,
            logo: $data->logo,
            maintainerIds: $data->maintainers,
            // A draft e-mails nobody - the admins hear of it when it is published (PublishCompetition)
            notifyAdmin: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false,
            organizationId: $data->organizationId,
            eligibility: $data->eligibility,
            eventTypes: EventTypes::of($data->eventTypes),
            isDraft: true,
        );
    }

    private function editCompetition(Competition $competition, CompetitionFormData $data): EditCompetition
    {
        return new EditCompetition(
            competitionId: $competition->id->toString(),
            name: $data->name ?? '',
            shortcut: $data->shortcut,
            description: $data->description,
            link: $data->link,
            // Not on this step (Extras, W6) - kept as they are
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            location: $data->isOnline === true ? null : $data->location,
            locationCountryCode: $data->locationCountryCode,
            dateFrom: $data->dateFrom,
            dateTo: $data->dateTo,
            isOnline: $data->isOnline === true,
            logo: $data->logo,
            maintainerIds: $data->maintainers,
            eligibility: $data->eligibility,
            eventTypes: EventTypes::of($data->eventTypes),
        );
    }

    private function newRound(string $competitionId, DateTimeImmutable $startsAt, string $timezone): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: Uuid::uuid7(),
            competitionId: $competitionId,
            name: $this->defaults->roundName(RoundCategory::Solo),
            minutesLimit: EventWizardDefaults::MINUTES_LIMIT,
            startsAt: $startsAt,
            timezone: $timezone,
            // Automatic colour, text picked for contrast (RoundBadgeColor)
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: RoundCategory::Solo,
            revealDelayMinutes: RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
        );
    }

    /**
     * The single round's start and zone - everything else kept as the round has it under the handler's lock. A start
     * moving earlier with a secret puzzle is refused (SecretPuzzlesWouldBeRevealed, never revealed without a yes).
     */
    private static function roundStart(CompetitionRound $round, string $competitionId, DateTimeImmutable $startsAt, string $timezone): EditCompetitionRound
    {
        return new EditCompetitionRound(
            roundId: $round->id->toString(),
            competitionId: $competitionId,
            name: $round->name,
            minutesLimit: $round->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
            startsAt: $startsAt,
            timezone: $timezone,
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            resultsLink: $round->resultsLink,
            keepFields: ['name', 'minutesLimit', 'badgeBackgroundColor', 'badgeTextColor', 'category', 'resultsLink'],
        );
    }

    /**
     * A refusal the form can explain - false for anything else (it is thrown on).
     *
     * @param FormInterface<CompetitionFormData> $form
     */
    private function explain(\Throwable $failure, FormInterface $form): bool
    {
        $secrets = EventWizardRefusal::find($failure, SecretPuzzlesWouldBeRevealed::class);
        if ($secrets !== null) {
            $form->get('startTime')->addError(new FormError($this->translator->trans('event_wizard.basics.secret_would_be_revealed')));

            return true;
        }

        $online = EventWizardRefusal::find($failure, OnlineTimingInUse::class);
        if ($online !== null) {
            $form->get('isOnline')->addError(new FormError($this->translator->trans($online->translationKey(), $online->translationParameters())));

            return true;
        }

        if (EventWizardRefusal::find($failure, OrganizationNotManaged::class) !== null) {
            // Left the organization's team since the entry screen
            $form->addError(new FormError($this->translator->trans('organizer_tools.form.organization_not_managed')));

            return true;
        }

        return false;
    }

    /**
     * @param list<OrganizationChoice> $choices
     */
    private static function organizationName(array $choices, null|string $organizationId): null|string
    {
        foreach ($choices as $choice) {
            if ($choice->id === $organizationId) {
                return $choice->name;
            }
        }

        return null;
    }

    /**
     * @param FormInterface<CompetitionFormData> $form
     */
    private static function clicked(FormInterface $form, string $button): bool
    {
        if ($form->has($button) === false) {
            return false;
        }

        $field = $form->get($button);

        return $field instanceof ClickableInterface && $field->isClicked();
    }
}
