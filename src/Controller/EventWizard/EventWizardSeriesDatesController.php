<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\FormData\EventWizardDatesFormData;
use SpeedPuzzling\Web\FormType\EventWizardBasicsFormType;
use SpeedPuzzling\Web\FormType\EventWizardDatesFormType;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Message\AddEventWizardEditions;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEventWizardSeries;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\EventWizardSeriesEdition;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDatesPrefill;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSeriesAccess;
use SpeedPuzzling\Web\Services\SeriesEditions\EditionsPreview;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\NewEdition;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Dates + each date's round" (W-creation-wizard-research.md §3.7, W3) - "Add several dates"
 * (the same rule, preview and idempotent ids: AddEditionsFormData, EditionsPreview) plus one round per new date: who
 * competes, the time limit, the start time (local in the series' zone - the zone of its last date's round, else of its
 * country, RoundTimezone), or "No rounds - just dates on the calendar". Puzzles are not asked per date (§3.7) - the
 * series page offers a quick "Add puzzle" per date.
 *
 * - In the wizard (`event_wizard_series_dates`, a draft series): the dates are drafts like the series; saved → Review.
 * - "Add the next dates" (`event_wizard_series_next_dates`, any series the player edits, from its management page):
 *   prefilled from the series' last dates (EventWizardDatesPrefill), the dates are drafts only while the series is;
 *   saved → the series' management page.
 *
 * Step 1 is a GET form (the rule in the query string), step 2 posts the checked days to the same URL - one
 * AddEventWizardEditions (dates + rounds in one transaction). A refused POST answers 422.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardSeriesDatesController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'event_wizard_dates_';

    public function __construct(
        private readonly EventWizardSeriesAccess $seriesAccess,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly GetEventWizardSeries $getEventWizardSeries,
        private readonly EditionsPreview $editionsPreview,
        private readonly EventsPageDates $eventsPageDates,
        private readonly FormFactoryInterface $formFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly EventWizardDefaults $defaults,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}/dates',
        name: 'event_wizard_series_dates',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}/next-dates',
        name: 'event_wizard_series_next_dates',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $inWizard = $request->attributes->get('_route') === 'event_wizard_series_dates';

        if ($inWizard) {
            $progress = $this->seriesAccess->draft($seriesId, EventWizardStep::Dates);

            if ($progress === null) {
                // Published meanwhile: its next dates
                return $this->redirectToRoute('event_wizard_series_next_dates', ['seriesId' => $seriesId]);
            }
        } else {
            $this->seriesAccess->state($seriesId);
            $progress = null;
        }

        $series = $this->getCompetitionSeries->byId($seriesId);
        $editions = $this->getEventWizardSeries->editions($seriesId);
        $today = OccurrenceDates::today($this->clock->now());

        $data = new EventWizardDatesFormData(
            weekday: (int) $today->format('N'),
            starting: $today,
            namePattern: AddEditionsFormData::defaultNamePattern($series->name),
        );
        EventWizardDatesPrefill::apply($data, $editions);

        /** @var FormInterface<EventWizardDatesFormData> $form */
        $form = $this->formFactory->createNamed('', EventWizardDatesFormType::class, $data);
        $isPost = $request->isMethod('POST');

        if ($isPost) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $seriesId, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            // The rule stays in the query string of the form's action: the dates are worked out again, never posted
            $form->submit($request->query->all());
        } else {
            $form->handleRequest($request);
        }

        $postedIds = $isPost ? $request->request->all('ids') : [];
        $preview = $form->isSubmitted() && $form->isValid() ? $this->editionsPreview->of($form->getData(), $seriesId, $request->getLocale(), $postedIds) : null;
        $selected = $isPost ? array_values(array_filter($request->request->all('selected'), is_string(...))) : null;
        $selectionError = null;

        if ($selected !== null && $preview !== null) {
            $formData = $form->getData();
            $newEditions = [];

            foreach ($preview as $item) {
                if (in_array($item['value'], $selected, true)) {
                    $newEditions[] = new NewEdition(competitionId: Uuid::fromString($item['id']), name: $item['name'], date: $item['date']);
                }
            }

            if ($newEditions === []) {
                $selectionError = $this->translator->trans('add_editions.none_selected', [], 'validators');
            } else {
                $rounds = $formData->hasRounds() ? $this->rounds($formData, $newEditions, $this->timezone($series, $editions), $form) : [];

                if ($rounds !== null) {
                    $created = $this->messageBus->dispatch(new AddEventWizardEditions(
                        editions: new AddEditions(
                            seriesId: $seriesId,
                            editions: $newEditions,
                            // Who can enter and the event types: the series' - its dates follow it
                            isDraft: $series->isDraft,
                        ),
                        rounds: $rounds,
                    ))->last(HandledStamp::class)?->getResult();

                    // A form sent again creates nothing - and says nothing
                    if (is_int($created) && $created > 0) {
                        $this->addFlash('success', $this->translator->trans(
                            $formData->hasRounds() ? 'event_wizard_series.flash.dates_added_with_rounds' : 'event_wizard_series.flash.dates_added',
                            ['%count%' => $created],
                        ));
                    }

                    if ($inWizard) {
                        return $this->redirectToRoute(EventWizardStep::SeriesReview->route(), ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
                    }

                    return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
                }
            }
        }

        $response = $this->render('event_wizard/series_dates.html.twig', [
            'form' => $form,
            'series' => $series,
            'progress' => $progress,
            'step' => EventWizardStep::Dates,
            'preview' => $preview,
            'selected' => $selected,
            'selection_error' => $selectionError,
            'csrf_token_id' => self::CSRF_TOKEN_PREFIX . $seriesId,
            'existing_count' => count($editions),
            'name_example' => $this->nameExample($form, $preview, $request->getLocale()),
            'timezone' => $this->timezone($series, $editions),
            'timezone_assumed' => RoundTimezone::isAssumed(null, $series->locationCountryCode?->name),
        ]);

        // A refused POST (an invalid rule, nothing checked, a time a clock change skips) never answers 200
        if ($isPost) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    /**
     * One round per new date, starting at the typed time of its day in $timezone - null (and an error on the start
     * time) when that time does not exist on some day (a daylight-saving change)
     *
     * @param list<NewEdition> $editions
     * @param FormInterface<EventWizardDatesFormData> $form
     * @return null|list<AddCompetitionRound>
     */
    private function rounds(EventWizardDatesFormData $data, array $editions, string $timezone, FormInterface $form): null|array
    {
        $startTime = EventWizardBasicsFormType::startTime($data->startTime ?? '');
        assert($startTime !== null);
        $category = $data->roundCategory();
        $rounds = [];
        $invalidDays = [];

        foreach ($editions as $edition) {
            try {
                $startsAt = RoundTimezone::toInstant($edition->date->format('Y-m-d') . ' ' . $startTime, $timezone);
            } catch (InvalidLocalTime) {
                $invalidDays[] = $edition->date->format('j.n.Y');

                continue;
            }

            $rounds[] = new AddCompetitionRound(
                roundId: Uuid::uuid7(),
                competitionId: $edition->competitionId->toString(),
                name: $this->defaults->roundName($category),
                minutesLimit: $data->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
                startsAt: $startsAt,
                timezone: $timezone,
                // Automatic colour, text picked for contrast (RoundBadgeColor)
                badgeBackgroundColor: null,
                badgeTextColor: null,
                category: $category,
                revealDelayMinutes: RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
                teamSize: $data->teamSize,
            );
        }

        if ($invalidDays !== []) {
            $form->get('startTime')->addError(new FormError($this->translator->trans('event_wizard_series.dates.invalid_local_time', [
                '%days%' => implode(', ', $invalidDays),
            ])));

            return null;
        }

        return $rounds;
    }

    /**
     * The zone of the series' last date's round (the organiser may have chosen it), else of its country
     *
     * @param list<EventWizardSeriesEdition> $editions
     */
    private function timezone(CompetitionSeriesOverview $series, array $editions): string
    {
        foreach (array_reverse($editions) as $edition) {
            if ($edition->roundTimezone !== null && RoundTimezone::isValid($edition->roundTimezone)) {
                return $edition->roundTimezone;
            }
        }

        return RoundTimezone::resolve(null, $series->locationCountryCode?->name);
    }

    /**
     * What the name pattern makes of the first date ("Cup 16 January 2027")
     *
     * @param FormInterface<EventWizardDatesFormData> $form
     * @param null|list<array{date: DateTimeImmutable, value: string, name: string, taken: bool, id: string}> $preview
     */
    private function nameExample(FormInterface $form, null|array $preview, string $locale): string
    {
        if ($preview !== null && $preview !== []) {
            return $preview[0]['name'];
        }

        $data = $form->getData();
        $day = $data->starting ?? OccurrenceDates::today($this->clock->now());

        return $data->nameFor($this->eventsPageDates->format($day, 'yMMMMd', $locale));
    }
}
