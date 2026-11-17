<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SeriesEditions;

use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;

/**
 * The preview of "Add several dates" (docs/features/organizations/README.md, P15/P16) - shared by the classic page
 * (AddEditionsController) and the event creation wizard's Dates step (W3): the proposed days with the name each edition
 * gets and the id it gets (made with the preview, carried by the page - so a form sent twice creates nothing twice); a
 * day that already holds an edition of the series is marked (and left unchecked by the page).
 */
readonly final class EditionsPreview
{
    public function __construct(
        private GetCompetitionSeries $getCompetitionSeries,
        private EventsPageDates $eventsPageDates,
    ) {
    }

    /**
     * @param array<mixed> $postedIds the ids the page carried, by day - a missing or malformed one is made anew
     * @return list<array{date: DateTimeImmutable, value: string, name: string, taken: bool, id: string}>
     */
    public function of(AddEditionsFormData $data, string $seriesId, string $locale, array $postedIds): array
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
