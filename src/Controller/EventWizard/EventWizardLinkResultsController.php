<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Message\LinkSolvingTimesToEvent;
use SpeedPuzzling\Web\Query\GetLinkableSolvingTimes;
use SpeedPuzzling\Web\Query\GetWaitingEventLinks;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\LinkableSolvingTime;
use SpeedPuzzling\Web\Results\LinkedSolvingTimes;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\EventWizard\EventLinkDecision;
use SpeedPuzzling\Web\Services\EventWizard\EventLinkRule;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Link my results" (docs/features/competitions-management/creation-wizard.md "Backfill") - for an event (an edition
 * included) or a series (a series pick, `?date=` the day the player typed). The signed-in player's own results that may
 * belong to it (GetLinkableSolvingTimes): of its puzzles (the ones visible now - RoundPuzzlesVisibleNow) or of the days
 * around it, the event's days first. Pre-ticked: a result of that day (of one of its puzzles when it has any) linked to
 * nothing yet; a result linked elsewhere is shown with that event's name, unticked. One click links the ticked ones
 * (LinkSolvingTimesToEvent - all or nothing; an event not public yet: the links wait for it).
 *
 * Who may open it: anybody signed in for a public event or series; for one that is not public only whoever may edit it
 * (EventLinkRule) - anybody else gets a 404, like its page. Then the player's own Done page (an event they edit) or the
 * event's page with a flash.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardLinkResultsController extends AbstractController
{
    public const string FORM = 'link_results';

    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly PlayerRepository $playerRepository,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private readonly GetLinkableSolvingTimes $getLinkableSolvingTimes,
        private readonly GetWaitingEventLinks $getWaitingEventLinks,
        private readonly EventLinkRule $eventLinkRule,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/link-results',
        name: 'event_wizard_link_results',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}/link-results',
        name: 'event_wizard_series_link_results',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, null|string $competitionId = null, null|string $seriesId = null): Response
    {
        $profile = $this->access->player();
        $player = $this->playerRepository->get($profile->playerId);

        try {
            $competition = $competitionId !== null ? $this->competitionRepository->get($competitionId) : null;
            $series = $competition === null && $seriesId !== null ? $this->competitionSeriesRepository->get($seriesId) : null;
        } catch (CompetitionNotFound | CompetitionSeriesNotFound) {
            throw new NotFoundHttpException();
        }

        $decision = $this->eventLinkRule->decide($player, $competition, $series);

        if ($decision === EventLinkDecision::Refused) {
            throw new NotFoundHttpException();
        }

        [$dayFrom, $dayTo] = $this->days($request, $competition);
        $eventPuzzleIds = $competition !== null ? $this->visiblePuzzleIds($competition) : [];

        $times = $this->getLinkableSolvingTimes->forPlayer(
            $profile->playerId,
            $competition?->id->toString(),
            $series?->id->toString(),
            $eventPuzzleIds,
            $dayFrom,
            $dayTo,
        );
        $waiting = $this->getWaitingEventLinks->forTimes(array_map(static fn (LinkableSolvingTime $time): string => $time->timeId, $times));
        $selectable = array_values(array_filter($times, static fn (LinkableSolvingTime $time): bool => $time->isLinkedHere === false));
        $preTicked = array_values(array_map(
            static fn (LinkableSolvingTime $time): string => $time->timeId,
            array_filter($selectable, static fn (LinkableSolvingTime $time): bool => $time->preTicked($eventPuzzleIds !== []) && isset($waiting[$time->timeId]) === false),
        ));

        $form = $this->createLinkForm($competition, $series, $request, $selectable, $preTicked);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<string> $ticked */
            $ticked = $form->get('times')->getData();
            $result = new LinkedSolvingTimes(0, 0, 0);

            if ($ticked !== []) {
                $envelope = $this->messageBus->dispatch(new LinkSolvingTimesToEvent(
                    actorPlayerId: $profile->playerId,
                    competitionId: $competition?->id->toString(),
                    seriesId: $series?->id->toString(),
                    solvingTimeIds: $ticked,
                ));
                $handled = $envelope->last(HandledStamp::class)?->getResult();
                $result = $handled instanceof LinkedSolvingTimes ? $handled : $result;
            }

            if ($result->linked > 0) {
                $this->addFlash('success', $this->translator->trans('event_wizard_backfill.link.flash_linked', ['%count%' => $result->linked]));
            }

            if ($result->waiting > 0) {
                $this->addFlash('success', $this->translator->trans('event_wizard_backfill.link.flash_waiting', ['%count%' => $result->waiting]));
            }

            if ($competition !== null && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString())) {
                return $this->redirectToRoute('event_wizard_past_done', ['competitionId' => $competition->id->toString()], Response::HTTP_SEE_OTHER);
            }

            $target = $competition !== null
                ? $this->competitionDetailUrl->of($competition->id->toString())
                : $this->generateUrl('competition_series_detail', ['slug' => $series?->slug]);

            return $this->redirect($target, Response::HTTP_SEE_OTHER);
        }

        return $this->render('event_wizard/link_results.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'series' => $series,
            'times' => $times,
            'waiting' => $waiting,
            'waits' => $decision === EventLinkDecision::Waits,
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            // The rail only on the player's own backfill (an event they edit) - a link-only visit is one page
            'progress' => $competition !== null && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString())
                ? $this->eventWizardSteps->forBackfill($competition->id->toString(), EventWizardStep::LinkResults)
                : null,
            'step' => EventWizardStep::LinkResults,
        ]);
    }

    /**
     * The event's days: its dates, else its first round's day; a series' the typed `?date=`
     *
     * @return array{0: null|DateTimeImmutable, 1: null|DateTimeImmutable}
     */
    private function days(Request $request, null|Competition $competition): array
    {
        if ($competition === null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date'));

            return $date === false ? [null, null] : [$date, $date];
        }

        if ($competition->dateFrom !== null) {
            return [$competition->dateFrom, $competition->dateTo ?? $competition->dateFrom];
        }

        $first = null;
        $last = null;

        foreach ($this->competitionRoundRepository->ofCompetition($competition) as $round) {
            $first = $first === null || $round->startsAt < $first ? $round->startsAt : $first;
            $last = $last === null || $round->startsAt > $last ? $round->startsAt : $last;
        }

        return [$first, $last];
    }

    /**
     * @return list<string>
     */
    private function visiblePuzzleIds(Competition $competition): array
    {
        $roundIds = array_map(
            static fn ($round): string => $round->id->toString(),
            $this->competitionRoundRepository->ofCompetition($competition),
        );

        $ids = [];

        foreach ($this->roundPuzzlesVisibleNow->forRounds($roundIds, $this->clock->now()) as $roundPuzzles) {
            foreach ($roundPuzzles->puzzles as $puzzle) {
                $ids[$puzzle->puzzleId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<LinkableSolvingTime> $selectable
     * @param list<string> $preTicked
     * @return FormInterface<array{times: list<string>}>
     */
    private function createLinkForm(null|Competition $competition, null|CompetitionSeries $series, Request $request, array $selectable, array $preTicked): FormInterface
    {
        $action = $competition !== null
            ? $this->generateUrl('event_wizard_link_results', ['competitionId' => $competition->id->toString()])
            : $this->generateUrl('event_wizard_series_link_results', array_filter(['seriesId' => $series?->id->toString(), 'date' => $request->query->getString('date')]));

        $choices = array_map(static fn (LinkableSolvingTime $time): string => $time->timeId, $selectable);

        /** @var FormInterface<array{times: list<string>}> $form */
        $form = $this->formFactory->createNamedBuilder(self::FORM, data: ['times' => $preTicked], options: ['action' => $action])
            ->add('times', ChoiceType::class, [
                'choices' => array_combine($choices, $choices),
                // The time id is the checkbox's own name - the template places each one in its time's row
                'choice_name' => static fn (null|string $choice): string => (string) $choice,
                'multiple' => true,
                'expanded' => true,
                'label' => false,
            ])
            ->getForm();

        return $form;
    }
}
