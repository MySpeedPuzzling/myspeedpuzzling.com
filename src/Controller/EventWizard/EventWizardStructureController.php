<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundHasResults;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\FormData\EventWizardScheduleFormData;
use SpeedPuzzling\Web\FormData\EventWizardScheduleRowFormData;
use SpeedPuzzling\Web\FormData\EventWizardStageFormData;
use SpeedPuzzling\Web\FormData\EventWizardStructureFormData;
use SpeedPuzzling\Web\FormType\EventWizardBasicsFormType;
use SpeedPuzzling\Web\FormType\EventWizardScheduleFormType;
use SpeedPuzzling\Web\FormType\EventWizardStructureFormType;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionStanding;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\SetUpEventWizardStructure;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetEventWizardStructureLock;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Results\EventWizardPlannedRound;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingsPerCategory;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardStructurePlanner;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Structure" on the several-rounds path (W-creation-wizard-research.md §3.5). Two forms on
 * one page, each written in one transaction (SetUpEventWizardStructure):
 *
 * - **Template** ("Create the rounds"): Qualification (stages) or Every round counts (solo/pairs/teams counts) -
 *   EventWizardStructurePlanner generates the rounds, their names and the schedule; with the competition toolkit
 *   (COMPETITION_TOOLKIT_MANAGE, W7) also an advancement step per stage arrow ("top N of each go on") and a standing per
 *   category ("add up the times"), never published. Shown open while the event has no round, then behind "Start again
 *   from a template" - which replaces every round (and the standings left without them).
 * - **Schedule table** (then "Save & continue"): every round's name, start, who competes and time limit, "remove", spare
 *   rows for new rounds ("Not sure yet" starts here, empty).
 *
 * Never exactly one round: that is the one-round path (EventWizardPath::of()) - the step refuses it. Once any round has
 * an entry, a result or a stopwatch run (GetEventWizardStructureLock) the step is read-only with links to the rounds'
 * own pages.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardStructureController extends AbstractController
{
    public const string TEMPLATE_FORM = 'event_wizard_structure';
    public const string SCHEDULE_FORM = 'event_wizard_schedule';

    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly CompetitionStandingRepository $competitionStandingRepository,
        private readonly GetEventWizardStructureLock $structureLock,
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly EventWizardStructurePlanner $planner,
        private readonly StandingsPerCategory $standingsPerCategory,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly ClockInterface $clock,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/structure',
        name: 'event_wizard_structure',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->access->player();
        $progress = $this->access->draft($competitionId, EventWizardStep::Structure);

        if ($progress === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        $competition = $this->competitionRepository->get($competitionId);
        $rounds = $this->competitionRoundRepository->ofCompetition($competition);
        $toolkit = $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        if ($rounds !== [] && $this->structureLock->isLocked($competitionId)) {
            // A POST of a page loaded before the first entry: the read-only page, refused (never a 200 to a form post)
            return $this->page($progress, $competition, $rounds, $toolkit, null, null, locked: true, refused: $request->isMethod('POST'));
        }

        $requested = EventWizardStructureTemplate::tryFrom($request->query->getString('structure'));
        $templateForm = $this->formFactory->createNamed(self::TEMPLATE_FORM, EventWizardStructureFormType::class, $this->templateDefaults($request, $rounds, $requested), [
            'action' => $request->getRequestUri(),
            'advancement' => $toolkit,
            'standings' => $toolkit,
            'replace' => $rounds !== [],
        ]);
        $scheduleData = $this->scheduleData($rounds);
        $scheduleForm = $this->formFactory->createNamed(self::SCHEDULE_FORM, EventWizardScheduleFormType::class, $scheduleData, [
            'action' => $request->getRequestUri(),
            'row_keys' => array_map(static fn (EventWizardScheduleRowFormData $row): bool => $row->isNew() === false, $scheduleData->rows),
        ]);

        $templateForm->handleRequest($request);
        $scheduleForm->handleRequest($request);

        if ($templateForm->isSubmitted() && $templateForm->isValid()) {
            $saved = $this->saveTemplate($templateForm, $competition, $rounds, $toolkit);

            if ($saved) {
                return $this->redirectToRoute('event_wizard_structure', ['competitionId' => $competitionId, '_fragment' => 'schedule'], Response::HTTP_SEE_OTHER);
            }
        }

        if ($scheduleForm->isSubmitted() && $scheduleForm->isValid() && $this->saveSchedule($scheduleForm, $competition, $rounds)) {
            if (self::clicked($scheduleForm, 'later')) {
                $this->addFlash('success', $this->translator->trans('event_wizard.flash.saved_for_later'));

                return $this->redirect($this->competitionDetailUrl->of($competitionId), Response::HTTP_SEE_OTHER);
            }

            $next = $progress->next(EventWizardStep::Structure) ?? EventWizardStep::Review;

            return $this->redirectToRoute($next->route(), ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        // A template chosen on the entry screen as "not sure yet" starts with the (empty) table
        $templateOpen = $rounds === [] && ($templateForm->isSubmitted() || $requested !== EventWizardStructureTemplate::Empty);

        return $this->page($progress, $competition, $rounds, $toolkit, $templateForm, $scheduleForm, templateOpen: $templateOpen);
    }

    /**
     * @param FormInterface<EventWizardStructureFormData> $form
     * @param list<CompetitionRound> $rounds
     */
    private function saveTemplate(FormInterface $form, Competition $competition, array $rounds, bool $toolkit): bool
    {
        $data = $form->getData();
        $competitionId = $competition->id->toString();
        $firstDay = $competition->dateFrom ?? $this->clock->now();
        $lastDay = $competition->dateTo ?? $firstDay;
        $days = max(1, (int) $firstDay->setTime(0, 0)->diff($lastDay->setTime(0, 0))->days + 1);
        $planned = $this->planner->plan($data, $firstDay, $days);

        if (count($planned) === 1) {
            $form->addError(new FormError($this->translator->trans('event_wizard.structure.one_round')));

            return false;
        }

        $timezone = RoundTimezone::resolve(null, $competition->locationCountryCode);
        $adds = [];
        /** @var array<int, list<string>> $stageRoundIds */
        $stageRoundIds = [];
        /** @var array<string, list<string>> $roundIdsByCategory */
        $roundIdsByCategory = [];

        foreach ($planned as $round) {
            try {
                $startsAt = RoundTimezone::toInstant($round->startsLocal, $timezone);
            } catch (InvalidLocalTime) {
                $form->get('startTime')->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));

                return false;
            }

            $add = $this->newRound($competitionId, $round, $startsAt, $timezone);
            $adds[] = $add;
            $roundId = $add->roundId->toString();
            $roundIdsByCategory[$round->category->value][] = $roundId;

            if ($round->stage !== null) {
                $stageRoundIds[$round->stage][] = $roundId;
            }
        }

        $steps = [];
        $standings = [];

        if ($toolkit && $data->template === EventWizardStructureTemplate::Qualification) {
            $steps = $this->advancementSteps($form, $competitionId, $data->usedStages(), $stageRoundIds);

            if ($steps === null) {
                return false;
            }
        }

        if ($toolkit && $data->template === EventWizardStructureTemplate::EveryRoundCounts && $data->sumPerCategory) {
            $standings = $this->standingsPerCategory->messages($competitionId, $roundIdsByCategory);
        }

        $message = new SetUpEventWizardStructure(
            competitionId: $competitionId,
            // Replacing every round: the old standings would be left without rounds (their steps go with their rounds)
            standingsToDelete: $rounds === [] ? [] : array_map(
                static fn ($standing): DeleteCompetitionStanding => new DeleteCompetitionStanding($standing->id->toString(), $competitionId),
                $this->competitionStandingRepository->ofCompetition($competition),
            ),
            deletes: array_map(static fn (CompetitionRound $round): DeleteCompetitionRound => self::deleteRound($round, $competitionId), $rounds),
            adds: $adds,
            advancementSteps: $steps,
            standings: $standings,
        );

        if ($this->dispatch($message, $form) === false) {
            return false;
        }

        if ($adds !== []) {
            $this->addFlash('success', $this->translator->trans('event_wizard.structure.flash.created', ['%count%' => count($adds)]));
        }

        return true;
    }

    /**
     * An advancement step per stage arrow with "top N" (toolkit, W7): the best N of each round of the stage go on to the
     * next one - one target = `single`, several = `balanced` (the serpentine). Null when a stage feeds a stage of
     * another category (AdvancementStepGraph refuses mixed categories) - said on the stage's field.
     *
     * @param FormInterface<EventWizardStructureFormData> $form
     * @param list<EventWizardStageFormData> $stages
     * @param array<int, list<string>> $stageRoundIds
     * @return null|list<CreateCompetitionAdvancementStep>
     */
    private function advancementSteps(FormInterface $form, string $competitionId, array $stages, array $stageRoundIds): null|array
    {
        $steps = [];
        $stageFields = array_values(array_filter(
            iterator_to_array($form->get('stages')),
            static fn (FormInterface $field): bool => $field->getData() instanceof EventWizardStageFormData && $field->getData()->isUsed(),
        ));

        foreach ($stages as $index => $stage) {
            $next = $stages[$index + 1] ?? null;

            if ($stage->advance === null || $next === null) {
                continue;
            }

            if ($stage->category !== $next->category) {
                $stageFields[$index]->get('advance')->addError(new FormError($this->translator->trans('event_wizard.structure.advance_mixed_categories')));

                return null;
            }

            $targets = $stageRoundIds[$index + 1] ?? [];
            $steps[] = new CreateCompetitionAdvancementStep(
                stepId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: trim((string) $stage->name) . ' → ' . trim((string) $next->name),
                settings: new AdvancementStepSettings(
                    sourceRoundIds: $stageRoundIds[$index] ?? [],
                    targetRoundIds: $targets,
                    distribution: count($targets) > 1 ? AdvanceDistribution::Balanced : AdvanceDistribution::Single,
                    qualifyCount: $stage->advance,
                    qualifyScope: AdvancementQualifyScope::PerRound,
                ),
            );
        }

        return $steps;
    }

    /**
     * @param FormInterface<EventWizardScheduleFormData> $form
     * @param list<CompetitionRound> $rounds
     */
    private function saveSchedule(FormInterface $form, Competition $competition, array $rounds): bool
    {
        $data = $form->getData();
        $competitionId = $competition->id->toString();

        if (count($data->usedRows()) === 1) {
            $form->addError(new FormError($this->translator->trans('event_wizard.structure.one_round')));

            return false;
        }

        $byId = [];
        foreach ($rounds as $round) {
            $byId[$round->id->toString()] = $round;
        }

        $deletes = [];
        $edits = [];
        $adds = [];
        $newZone = RoundTimezone::resolve(null, $competition->locationCountryCode);
        $rowsForm = $form->get('rows');

        foreach ($data->rows as $key => $row) {
            $round = $row->roundId !== null ? ($byId[$row->roundId] ?? null) : null;

            if ($row->isNew() === false && $round === null) {
                continue;
            }

            if ($row->isUsed() === false) {
                if ($round !== null) {
                    $deletes[] = self::deleteRound($round, $competitionId);
                }

                continue;
            }

            $zone = $round !== null ? ($round->timezone ?? $round->displayTimezone()) : $newZone;
            $localStart = $row->localStart();
            assert($localStart !== null && $row->minutesLimit !== null);

            try {
                $startsAt = RoundTimezone::toInstant($localStart, $zone);
            } catch (InvalidLocalTime) {
                $rowsForm->get((string) $key)->get('start')->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));

                return false;
            }

            $name = trim((string) $row->name);
            $teamSize = $row->category === RoundCategory::Team ? $row->teamSize : null;

            if ($round === null) {
                $adds[] = new AddCompetitionRound(
                    roundId: Uuid::uuid7(),
                    competitionId: $competitionId,
                    name: $name,
                    minutesLimit: $row->minutesLimit,
                    startsAt: $startsAt,
                    timezone: $zone,
                    badgeBackgroundColor: null,
                    badgeTextColor: null,
                    category: $row->category,
                    revealDelayMinutes: RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
                    teamSize: $teamSize,
                );

                continue;
            }

            $unchanged = $round->name === $name
                && $round->startsAt->getTimestamp() === $startsAt->getTimestamp()
                && $round->category === $row->category
                && $round->minutesLimit === $row->minutesLimit
                && ($row->category !== RoundCategory::Team || $round->teamSize === $teamSize);

            if ($unchanged) {
                continue;
            }

            $edits[] = new EditCompetitionRound(
                roundId: $round->id->toString(),
                competitionId: $competitionId,
                name: $name,
                minutesLimit: $row->minutesLimit,
                startsAt: $startsAt,
                timezone: $zone,
                badgeBackgroundColor: $round->badgeBackgroundColor,
                badgeTextColor: $round->badgeTextColor,
                category: $row->category,
                resultsLink: $round->resultsLink,
                // A start moving a secret puzzle's reveal earlier is refused (the round's own form asks for it)
                refuseToReveal: true,
                keepFields: ['badgeBackgroundColor', 'badgeTextColor', 'resultsLink'],
                teamSize: $teamSize,
                clearTeamSize: $row->category === RoundCategory::Team && $teamSize === null,
            );
        }

        if ($deletes === [] && $edits === [] && $adds === []) {
            return true;
        }

        return $this->dispatch(new SetUpEventWizardStructure(
            competitionId: $competitionId,
            deletes: $deletes,
            edits: $edits,
            adds: $adds,
        ), $form);
    }

    /**
     * @param FormInterface<EventWizardStructureFormData>|FormInterface<EventWizardScheduleFormData> $form
     */
    private function dispatch(SetUpEventWizardStructure $message, FormInterface $form): bool
    {
        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException | HttpExceptionInterface $failure) {
            $explained = $this->explanation($failure);

            if ($explained === null) {
                throw $failure;
            }

            $form->addError(new FormError($explained));

            return false;
        }

        return true;
    }

    /**
     * A refusal of one of the step's messages, in words - null for anything else (it is thrown on). Which round it
     * concerns is in the message where the refusal knows it.
     */
    private function explanation(\Throwable $failure): null|string
    {
        if (EventWizardRefusal::find($failure, SecretPuzzlesWouldBeRevealed::class) !== null) {
            return $this->translator->trans('event_wizard.structure.secret_would_be_revealed');
        }

        if (EventWizardRefusal::find($failure, CompetitionRoundHasResults::class) !== null) {
            return $this->translator->trans('event_wizard.structure.round_has_results');
        }

        $inCategory = EventWizardRefusal::find($failure, PuzzleAlreadyInCompetitionRoundCategory::class);
        if ($inCategory !== null) {
            return $this->translator->trans('competition.round.form.puzzle_already_in_category', ['%round%' => $inCategory->conflictingRoundName]);
        }

        $protected = EventWizardRefusal::find($failure, OfficialResultsProtected::class);
        if ($protected !== null) {
            return $this->translator->trans($protected->translationKey());
        }

        $online = EventWizardRefusal::find($failure, OnlineTimingInUse::class);
        if ($online !== null) {
            return $this->translator->trans($online->translationKey(), $online->translationParameters());
        }

        $step = EventWizardRefusal::find($failure, InvalidAdvancementStep::class);
        if ($step !== null) {
            return $this->translator->trans($step->translationKey());
        }

        $standing = EventWizardRefusal::find($failure, InvalidCompetitionStanding::class);
        if ($standing !== null) {
            return $this->translator->trans($standing->translationKey());
        }

        return null;
    }

    private function newRound(string $competitionId, EventWizardPlannedRound $round, DateTimeImmutable $startsAt, string $timezone): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: Uuid::uuid7(),
            competitionId: $competitionId,
            name: $round->name,
            minutesLimit: $round->minutesLimit,
            startsAt: $startsAt,
            timezone: $timezone,
            // Automatic colour, text picked for contrast (RoundBadgeColor)
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $round->category,
            revealDelayMinutes: RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
            teamSize: $round->teamSize,
        );
    }

    private static function deleteRound(CompetitionRound $round, string $competitionId): DeleteCompetitionRound
    {
        return new DeleteCompetitionRound(
            roundId: $round->id->toString(),
            competitionId: $competitionId,
            refuseWhenItHasResults: true,
            refuseToReveal: true,
        );
    }

    /**
     * The template's starting values: the template the entry screen's answer chose; three stages (groups → semifinals →
     * final, the WJPC shape) and 6 solo / 6 pairs / 6 teams of 4 (the World Series shape); the first start from Basics
     * (`?start=`), else the first round's, else 10:00; a round every 2 hours.
     *
     * @param list<CompetitionRound> $rounds
     */
    private function templateDefaults(Request $request, array $rounds, null|EventWizardStructureTemplate $requested): EventWizardStructureFormData
    {
        $data = new EventWizardStructureFormData();
        $data->template = $requested ?? EventWizardStructureTemplate::Qualification;

        if ($data->template === EventWizardStructureTemplate::Empty) {
            $data->template = EventWizardStructureTemplate::Qualification;
        }

        $data->stages[0] = new EventWizardStageFormData($this->translator->trans('event_wizard.structure.default_group'), 4, RoundCategory::Solo, null, 90, 10);
        $data->stages[1] = new EventWizardStageFormData($this->translator->trans('event_wizard.structure.default_semifinal'), 2, RoundCategory::Solo, null, 90, 15);
        $data->stages[2] = new EventWizardStageFormData($this->translator->trans('event_wizard.structure.default_final'), 1, RoundCategory::Solo, null, 120);
        $data->soloRounds = 6;
        $data->pairsRounds = 6;
        $data->teamsRounds = 6;

        $start = EventWizardBasicsFormType::startTime($request->query->getString('start'));

        if ($start === null && $rounds !== []) {
            $first = $rounds[0];
            $start = RoundTimezone::toLocal($first->startsAt, $first->displayTimezone())->format('H:i');
        }

        $data->startTime = $start ?? '10:00';

        return $data;
    }

    /**
     * @param list<CompetitionRound> $rounds
     */
    private function scheduleData(array $rounds): EventWizardScheduleFormData
    {
        $rows = [];

        foreach ($rounds as $round) {
            $local = RoundTimezone::toLocal($round->startsAt, $round->displayTimezone());
            $rows[$round->id->toString()] = new EventWizardScheduleRowFormData(
                roundId: $round->id->toString(),
                name: $round->name,
                start: $local->format('Y-m-d\TH:i'),
                category: $round->category,
                teamSize: $round->teamSize,
                minutesLimit: $round->minutesLimit,
            );
        }

        // Spare rows: a new round's start defaults to two hours after the last one (or nothing - typed)
        for ($index = 0; $index < EventWizardScheduleFormData::NEW_ROWS; $index++) {
            $rows['new' . $index] = new EventWizardScheduleRowFormData(minutesLimit: 90);
        }

        return new EventWizardScheduleFormData($rows);
    }

    /**
     * @param list<CompetitionRound> $rounds
     * @param null|FormInterface<EventWizardStructureFormData> $templateForm
     * @param null|FormInterface<EventWizardScheduleFormData> $scheduleForm
     */
    private function page(
        EventWizardProgress $progress,
        Competition $competition,
        array $rounds,
        bool $toolkit,
        null|FormInterface $templateForm,
        null|FormInterface $scheduleForm,
        bool $locked = false,
        bool $templateOpen = false,
        bool $refused = false,
    ): Response {
        $competitionId = $competition->id->toString();
        $refused = $refused || ($templateForm?->isSubmitted() === true && $templateForm->isValid() === false)
            || ($scheduleForm?->isSubmitted() === true && $scheduleForm->isValid() === false);

        return $this->render('event_wizard/structure.html.twig', [
            'progress' => $progress,
            'step' => EventWizardStep::Structure,
            'competition_id' => $competitionId,
            'rounds' => $rounds,
            'locked' => $locked,
            'toolkit' => $toolkit,
            'template_form' => $templateForm?->createView(),
            'schedule_form' => $scheduleForm?->createView(),
            'template_open' => $templateOpen,
            'advancement_steps' => $toolkit && $rounds !== [] ? $this->getCompetitionAdvancementSteps->ofCompetition($competitionId) : [],
            'standings' => $toolkit && $rounds !== [] ? $this->getCompetitionStandings->ofCompetition($competitionId) : [],
        ], new Response(status: $refused ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param FormInterface<EventWizardScheduleFormData> $form
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
