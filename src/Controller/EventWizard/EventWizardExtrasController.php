<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\EventWizardExtraRefused;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\FormData\EventWizardExtrasFormData;
use SpeedPuzzling\Web\FormType\EventWizardExtrasFormType;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\ChangeCompetitorPageSettings;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Message\RemoveCompetitionReferee;
use SpeedPuzzling\Web\Message\SaveEventWizardExtras;
use SpeedPuzzling\Web\MessageHandler\AddCompetitionRefereeHandler;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\CompetitionRefereeListItem;
use SpeedPuzzling\Web\Results\EventWizardExtrasState;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPresence;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardExtras;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Value\CompetitionRefereeAddition;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use SpeedPuzzling\Web\Value\EventTypes;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Extras" (W-creation-wizard-research.md §2 principle 4, §3.8): opt-in features as plain
 * switches, all off for a new event, one sentence each; switching one on reveals only its must-ask fields. The switches
 * start from what the event has (coming back shows them as they are); "Continue" with nothing changed writes nothing.
 *
 * Each row writes through its existing message, only when it changed: registration (ChangeCompetitionRegistrationSettings
 * - off keeps the settings, like unticking it on the registration page), registration/results elsewhere (EditCompetition
 * with every other field as it is), judges (AddCompetitionReferee per player, the referees page's outcomes; off removes
 * them - RemoveCompetitionReferee), and for whom COMPETITION_TOOLKIT_MANAGE grants: QR tags + competitor pages + time
 * proposals (one ChangeCompetitorPageSettings), a timer screen / an overlay (AddCompetitionDisplay from a preset - once
 * the event has one it is managed on the displays page, never deleted from here: the display editor is its deep
 * editor), overall standings for several rounds (CreateCompetitionStanding per category, as the standings page's
 * "per category" - once standings exist: managed there). Table numbers are asked nowhere: the checklist has them.
 *
 * Every row is validated before the first write, then every changed row is written in one transaction
 * (SaveEventWizardExtras): a row refused while writing (the display limit, a standing) writes nothing at all - 422 with
 * that row's error.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardExtrasController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardExtras $extras,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly CompetitionDisplaysPresence $displaysPresence,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/extras',
        name: 'event_wizard_extras',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $player = $this->access->player();
        $progress = $this->access->draft($competitionId, EventWizardStep::Extras);

        if ($progress === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        $state = $this->extras->of($competitionId, $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId));
        $form = $this->createForm(EventWizardExtrasFormType::class, EventWizardExtrasFormData::fromState($state), [
            'action' => $this->generateUrl('event_wizard_extras', ['competitionId' => $competitionId]),
            'state' => $state,
        ]);
        $form->handleRequest($request);

        // Judges switched on without anybody to add: said (with every other refusal), never silently nothing
        if ($form->isSubmitted() && $form->getData()->judges && $state->referees === [] && $form->getData()->judgePlayers === []) {
            $form->get('judgePlayers')->addError(new FormError($this->translator->trans('event_wizard.extras.judges_required')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $registration = $this->registrationMessage($form, $data, $state);


            if ($form->getErrors(true)->count() === 0) {
                $refused = $this->write($form, $data, $state, $registration, $player->playerId, $request->getLocale());

                if ($refused === false) {
                    $next = $progress->next(EventWizardStep::Extras) ?? EventWizardStep::Review;

                    return $this->redirectToRoute($next->route(), ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
                }

                // Nothing was written - the page shows the event as it is
                $state = $this->extras->of($competitionId, $state->toolkit);
            }
        }

        // A refused form answers 422 (render() sets it when handed the form itself)
        return $this->render('event_wizard/extras.html.twig', [
            'form' => $form,
            'progress' => $progress,
            'step' => EventWizardStep::Extras,
            'extras' => $state,
            'max_referees' => AddCompetitionRefereeHandler::MAX_REFEREES,
        ]);
    }

    /**
     * The registration settings to save - null when nothing changes; a local time that does not exist is a form error.
     *
     * @param FormInterface<EventWizardExtrasFormData> $form
     */
    private function registrationMessage(FormInterface $form, EventWizardExtrasFormData $data, EventWizardExtrasState $state): null|ChangeCompetitionRegistrationSettings
    {
        if ($data->registration === false) {
            // Switched off: management off, the settings kept (the registration page's unticking)
            return $state->registrationManaged === false ? null : new ChangeCompetitionRegistrationSettings(
                competitionId: $state->competitionId,
                registrationManaged: false,
                capacity: $state->capacity,
                registrationOpensAt: $state->registrationOpensAt,
                registrationClosesAt: $state->registrationClosesAt,
                timezone: $state->registrationTimezone,
                entryFeeText: $state->entryFeeText,
                paymentInstructions: $state->paymentInstructions,
            );
        }

        $instants = [];

        foreach (['opensAt' => $data->opensAt, 'closesAt' => $data->closesAt] as $field => $wallClock) {
            try {
                $instants[$field] = $wallClock !== null ? RoundTimezone::toInstant($wallClock->format('Y-m-d H:i'), $state->registrationTimezone) : null;
            } catch (InvalidLocalTime) {
                $form->get($field)->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));
            }
        }

        if (array_key_exists('opensAt', $instants) === false || array_key_exists('closesAt', $instants) === false) {
            return null;
        }

        $message = new ChangeCompetitionRegistrationSettings(
            competitionId: $state->competitionId,
            registrationManaged: true,
            capacity: $data->capacity,
            registrationOpensAt: $instants['opensAt'],
            registrationClosesAt: $instants['closesAt'],
            timezone: $state->registrationTimezone,
            entryFeeText: $data->trimmedEntryFee(),
            // Not asked here - kept (the registration page has it)
            paymentInstructions: $state->paymentInstructions,
        );

        $unchanged = $state->registrationManaged
            && $message->capacity === $state->capacity
            && $message->registrationOpensAt?->getTimestamp() === $state->registrationOpensAt?->getTimestamp()
            && $message->registrationClosesAt?->getTimestamp() === $state->registrationClosesAt?->getTimestamp()
            && $message->entryFeeText === $state->entryFeeText;

        return $unchanged ? null : $message;
    }

    /**
     * Writes every row that changed in one transaction (SaveEventWizardExtras) - true when a row was refused (its field
     * says why) and nothing was written
     *
     * @param FormInterface<EventWizardExtrasFormData> $form
     */
    private function write(
        FormInterface $form,
        EventWizardExtrasFormData $data,
        EventWizardExtrasState $state,
        null|ChangeCompetitionRegistrationSettings $registration,
        string $playerId,
        string $locale,
    ): bool {
        $competitionId = $state->competitionId;

        // Registration / results elsewhere: the links, every other field of the event as it is
        $registrationLink = $data->trimmedRegistrationLink();
        $resultsLink = $data->trimmedResultsLink();
        $links = $registrationLink !== $state->registrationLink || $resultsLink !== $state->resultsLink
            ? self::links($this->competitionRepository->get($competitionId), $registrationLink, $resultsLink)
            : null;

        [$removeReferees, $addReferees] = $this->judges($data, $state, $playerId);

        $competitorPages = null;

        if ($form->has('qrTags')) {
            $settings = new CompetitorPageSettings(
                competitorPages: $data->competitorPages,
                liveResultsBeforePublishing: $data->competitorPages && $data->liveResultsBeforePublishing,
                timeProposals: $data->timeProposals,
                qrTagMode: $data->qrTags ? $data->qrTagMode : null,
            );

            if ($settings != $state->competitorPageSettings) {
                $competitorPages = new ChangeCompetitorPageSettings($competitionId, $settings);
            }
        }

        $displays = [];

        if ($form->has('timerScreen') && $data->timerScreen) {
            $displays['timerScreen'] = $this->display($competitionId, DisplayKind::Screen, $data->screenPreset, 'event_wizard.extras.screen_name', $locale, $playerId);
        }

        if ($form->has('overlay') && $data->overlay) {
            $displays['overlay'] = $this->display($competitionId, DisplayKind::Overlay, $data->overlayPreset, 'event_wizard.extras.overlay_name', $locale, $playerId);
        }

        $message = new SaveEventWizardExtras(
            competitionId: $competitionId,
            registration: $registration,
            links: $links,
            removeReferees: $removeReferees,
            addReferees: $addReferees,
            competitorPages: $competitorPages,
            displays: $displays,
            standings: $form->has('standings') && $data->standings ? $this->standingsPerCategory($competitionId) : [],
        );

        if ($message->isEmpty()) {
            return false;
        }

        try {
            $outcomes = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException | HttpExceptionInterface $failure) {
            $refused = EventWizardRefusal::find($failure, EventWizardExtraRefused::class);

            if ($refused === null) {
                throw $failure;
            }

            // Rolled back - the event in this request's memory still has the rows written before the refusal
            // (project memory "rolled-back handler leaks"): read it again, the page shows the event as it is
            $this->entityManager->refresh($this->competitionRepository->get($competitionId));

            $standingRefusal = EventWizardRefusal::find($refused, InvalidCompetitionStanding::class);
            $form->get($refused->row)->addError(new FormError($standingRefusal !== null
                ? $this->translator->trans($standingRefusal->translationKey())
                : $this->translator->trans('competition_display.flash.limit_reached', ['%max%' => CompetitionDisplay::MAX_PER_COMPETITION])));

            return true;
        }

        if ($displays !== []) {
            // After the commit: the event has displays now - its writes start signalling them (competition toolkit D5)
            $this->displaysPresence->changed($competitionId);
        }

        if ($message->standings !== []) {
            // After the commit: the public standings' guest snapshots start a new generation (competition toolkit v2 S1)
            $this->displayLiveUpdates->standingsChanged($competitionId);
        }

        // Only what did not go as asked is said - an added judge shows on Review
        $values = [];

        foreach (is_array($outcomes) ? $outcomes : [] as $outcome) {
            assert($outcome instanceof CompetitionRefereeAddition);
            $values[] = $outcome->value;
        }

        $counts = array_count_values($values);

        foreach ($counts as $outcome => $count) {
            if ($outcome !== CompetitionRefereeAddition::Added->value) {
                $this->addFlash('warning', $this->translator->trans('competition_referees.outcome.' . $outcome, [
                    '%count%' => $count,
                    '%limit%' => AddCompetitionRefereeHandler::MAX_REFEREES,
                ]));
            }
        }

        return false;
    }

    /**
     * Judges: the players picked are added (the referees page's outcomes as flashes - somebody already a judge, an
     * organiser…); switched off, the event's judges are removed.
     *
     * @return array{list<RemoveCompetitionReferee>, list<AddCompetitionReferee>}
     */
    private function judges(EventWizardExtrasFormData $data, EventWizardExtrasState $state, string $playerId): array
    {
        if ($data->judges === false) {
            return [
                array_map(static fn (CompetitionRefereeListItem $referee): RemoveCompetitionReferee => new RemoveCompetitionReferee($state->competitionId, $referee->playerId), $state->referees),
                [],
            ];
        }

        $existing = array_map(static fn (CompetitionRefereeListItem $referee): string => $referee->playerId, $state->referees);
        $adds = [];

        foreach ($data->judgePlayers as $judgeId) {
            if (in_array($judgeId, $existing, true) === false) {
                $adds[] = new AddCompetitionReferee($state->competitionId, $judgeId, $playerId);
            }
        }

        return [[], $adds];
    }

    private function display(string $competitionId, DisplayKind $kind, DisplayPreset $preset, string $nameKey, string $locale, string $playerId): AddCompetitionDisplay
    {
        return new AddCompetitionDisplay(
            displayId: Uuid::uuid7(),
            competitionId: $competitionId,
            kind: $kind,
            name: $this->translator->trans($nameKey),
            locale: in_array($locale, CompetitionDisplay::LOCALES, true) ? $locale : 'en',
            actorPlayerId: $playerId,
            presetKey: $preset,
        );
    }

    /**
     * One standing per category of the event's rounds - the standings page's "per category"
     * (CreateStandingsPerCategoryController); offered only while the event has none.
     */
    /**
     * @return list<CreateCompetitionStanding>
     */
    private function standingsPerCategory(string $competitionId): array
    {
        /** @var array<string, list<string>> $roundIdsByCategory */
        $roundIdsByCategory = [];

        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            $roundIdsByCategory[$round->category->value][] = $round->id;
        }

        $standings = [];

        foreach (RoundCategory::cases() as $category) {
            $roundIds = $roundIdsByCategory[$category->value] ?? [];

            if ($roundIds === []) {
                continue;
            }

            $standings[] = new CreateCompetitionStanding(
                standingId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: $this->translator->trans('competition_standing.per_category_name', [
                    '%category%' => $this->translator->trans('competition.round.category.' . $category->value),
                ]),
                category: $category,
                roundIds: $roundIds,
                rules: new StandingRules(),
            );
        }

        return $standings;
    }

    /**
     * The event as it is with new registration / results links (the edit page's message keeps nothing by itself)
     */
    private static function links(Competition $competition, null|string $registrationLink, null|string $resultsLink): EditCompetition
    {
        return new EditCompetition(
            competitionId: $competition->id->toString(),
            name: $competition->name,
            shortcut: $competition->shortcut,
            description: $competition->description,
            link: $competition->link,
            registrationLink: $registrationLink,
            resultsLink: $resultsLink,
            location: $competition->location,
            locationCountryCode: $competition->locationCountryCode,
            dateFrom: $competition->dateFrom,
            dateTo: $competition->dateTo,
            isOnline: $competition->isOnline,
            logo: null,
            maintainerIds: array_values(array_map(static fn (Player $maintainer): string => $maintainer->id->toString(), $competition->maintainers->toArray())),
            eligibility: $competition->eligibility,
            eventTypes: EventTypes::fromStored($competition->eventTypes),
        );
    }
}
