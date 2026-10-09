<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\FormType\AddEditionsFormType;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Value\NewEdition;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * "Add several dates" (docs/features/organizations/README.md "Add several dates", P15/P16): the rule (every week on a
 * weekday / the Nth or the last weekday of the month) or picked days come in the query string, the page previews the
 * dates with their names - a checked box each, a day already holding an edition of the series unchecked and marked -
 * and one POST to the same URL creates the checked ones (AddEditions, at most 24), as drafts with "Save as draft".
 * Nothing is created before the organiser saw the dates; nothing of the rule is stored.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddEditionsController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'add_editions_';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $formFactory,
        private readonly EventsPageDates $eventsPageDates,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-edice/{seriesId}',
            'en' => '/en/add-editions/{seriesId}',
            'es' => '/es/add-editions/{seriesId}',
            'ja' => '/ja/add-editions/{seriesId}',
            'fr' => '/fr/add-editions/{seriesId}',
            'de' => '/de/add-editions/{seriesId}',
        ],
        name: 'add_editions',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->getCompetitionSeries->byId($seriesId);
        $today = OccurrenceDates::today($this->clock->now());

        $data = new AddEditionsFormData(
            weekday: (int) $today->format('N'),
            starting: $today,
            namePattern: AddEditionsFormData::defaultNamePattern($series->name),
        );

        /** @var FormInterface<AddEditionsFormData> $form */
        $form = $this->formFactory->createNamed('', AddEditionsFormType::class, $data);
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

        // The id each day's edition gets - made with the preview, sent back with the form, so a form sent twice creates
        // nothing twice (AddEditions skips ids that exist)
        $postedIds = $isPost ? $request->request->all('ids') : [];
        $preview = $form->isSubmitted() && $form->isValid() ? $this->preview($form->getData(), $seriesId, $request->getLocale(), $postedIds) : null;
        $selectionError = null;
        // The days checked in a refused POST stay checked; a fresh preview checks every free day
        $selected = $isPost ? array_values(array_filter($request->request->all('selected'), is_string(...))) : null;

        if ($selected !== null && $preview !== null) {
            $editions = [];

            foreach ($preview as $item) {
                if (in_array($item['value'], $selected, true)) {
                    $editions[] = new NewEdition(competitionId: Uuid::fromString($item['id']), name: $item['name'], date: $item['date']);
                }
            }

            if ($editions !== []) {
                $isDraft = $request->request->has('saveDraft');

                $envelope = $this->messageBus->dispatch(new AddEditions(
                    seriesId: $seriesId,
                    editions: $editions,
                    eligibility: $form->getData()->eligibility,
                    isDraft: $isDraft,
                ));
                $created = $envelope->last(HandledStamp::class)?->getResult();

                // A form sent again creates nothing - and says nothing
                if (is_int($created) && $created > 0) {
                    $this->addFlash('success', $this->translator->trans(
                        $isDraft ? 'organizer_tools.flash.editions_added_draft' : 'organizer_tools.flash.editions_added',
                        ['%count%' => $created],
                    ));
                }

                return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
            }

            $selectionError = $this->translator->trans('add_editions.none_selected', [], 'validators');
        }

        $response = $this->render('add_editions.html.twig', [
            'form' => $form,
            'series' => $series,
            'preview' => $preview,
            'selection_error' => $selectionError,
            'selected' => $selected,
            'csrf_token_id' => self::CSRF_TOKEN_PREFIX . $seriesId,
        ]);

        // A refused POST (an invalid rule, nothing checked) never answers 200 - Turbo would drop it
        if ($isPost) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    /**
     * The proposed days with the name each edition gets; a day that already holds an edition of the series is marked
     * (and left unchecked by the page)
     *
     * @param array<mixed> $postedIds the ids the page carried, by day - a missing or malformed one is made anew
     * @return list<array{date: DateTimeImmutable, value: string, name: string, taken: bool, id: string}>
     */
    private function preview(AddEditionsFormData $data, string $seriesId, string $locale, array $postedIds): array
    {
        $taken = $this->takenDays($seriesId);
        $items = [];

        foreach ($data->proposedDates() as $date) {
            $value = $date->format('Y-m-d');

            $items[] = [
                'date' => $date,
                'value' => $value,
                'name' => $data->nameFor($this->eventsPageDates->format($date, 'yMMMMd', $locale)),
                'taken' => isset($taken[$value]),
                'id' => isset($postedIds[$value]) && is_string($postedIds[$value]) && Uuid::isValid($postedIds[$value])
                    ? strtolower($postedIds[$value])
                    : Uuid::uuid7()->toString(),
            ];
        }

        return $items;
    }

    /**
     * The days of the series' editions: their own dates (a span: every day of it), else the day of their first round
     *
     * @return array<string, true>
     */
    private function takenDays(string $seriesId): array
    {
        $days = [];

        /** @var SeriesEdition $edition */
        foreach ([...$this->getCompetitionSeries->upcomingEditions($seriesId), ...$this->getCompetitionSeries->pastEditions($seriesId)] as $edition) {
            if ($edition->dateFrom !== null) {
                $to = $edition->dateTo ?? $edition->dateFrom;
                $day = $edition->dateFrom;

                for ($i = 0; $day <= $to && $i <= 31; $i++) {
                    $days[$day->format('Y-m-d')] = true;
                    $day = $day->modify('+1 day');
                }
            } elseif ($edition->startsAt !== null) {
                $days[$edition->startsAt->setTimezone(new DateTimeZone($edition->timezone))->format('Y-m-d')] = true;
            }
        }

        return $days;
    }
}
