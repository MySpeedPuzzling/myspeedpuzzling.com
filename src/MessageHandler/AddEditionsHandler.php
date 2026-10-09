<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * "Add several dates" (docs/features/organizations/README.md, D15): every edition like AddEdition - the series' place,
 * dated one day (00:00 UTC, dateFrom = dateTo) - with a slug from its name, unique in the series and among the batch
 * (`-2`, `-3`, … - never a random suffix, the organiser sees the names). An edition whose id exists already, or whose
 * day an edition of the series is dated, is skipped (a form sent twice) - under the series' lock (AddEditions).
 */
#[AsMessageHandler]
readonly final class AddEditionsHandler
{
    public function __construct(
        private CompetitionSeriesRepository $seriesRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionSlugGenerator $slugGenerator,
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * @return int how many editions were created - an id or a day the series has an edition of already is skipped
     */
    public function __invoke(AddEditions $message): int
    {
        $count = count($message->editions);

        if ($count < 1 || $count > AddEditions::MAX) {
            throw new InvalidArgumentException(sprintf('Add 1 to %d editions at once.', AddEditions::MAX));
        }

        $series = $this->seriesRepository->get($message->seriesId);
        $eligibility = $message->eligibility !== null && trim($message->eligibility) !== '' ? trim($message->eligibility) : null;
        $utc = new DateTimeZone('UTC');
        $slugsOfBatch = [];
        $takenDays = $this->takenDays($series);
        $created = 0;

        foreach ($message->editions as $edition) {
            $dayKey = $edition->date->format('Y-m-d');

            // The form sent again (a double click, a reload) or a day taken meanwhile: nothing twice
            if (isset($takenDays[$dayKey]) || $this->competitionRepository->has($edition->competitionId)) {
                continue;
            }

            $takenDays[$dayKey] = true;
            $created++;
            $slug = $this->uniqueSlug($edition->name, $message->seriesId, $slugsOfBatch);
            $slugsOfBatch[$slug] = true;
            $day = new DateTimeImmutable($edition->date->format('Y-m-d'), $utc);

            $competition = new Competition(
                id: $edition->competitionId,
                name: $edition->name,
                slug: $slug,
                shortcut: null,
                logo: null,
                description: null,
                link: null,
                registrationLink: null,
                resultsLink: null,
                location: $series->location,
                locationCountryCode: $series->locationCountryCode,
                dateFrom: $day,
                dateTo: $day,
                tag: null,
                isOnline: $series->isOnline,
                series: $series,
                isDraft: $message->isDraft,
                eligibility: $eligibility,
            );

            $this->competitionRepository->save($competition);
        }

        return $created;
    }

    /**
     * The days the series' editions are dated (every day of a span, at most 31)
     *
     * @return array<string, true>
     */
    private function takenDays(CompetitionSeries $series): array
    {
        $days = [];

        foreach ($this->competitionRepository->editionsOfSeries($series) as $edition) {
            if ($edition->dateFrom === null) {
                continue;
            }

            $to = $edition->dateTo ?? $edition->dateFrom;
            $day = $edition->dateFrom;

            for ($i = 0; $day <= $to && $i <= 31; $i++) {
                $days[$day->format('Y-m-d')] = true;
                $day = $day->modify('+1 day');
            }
        }

        return $days;
    }

    /**
     * @param array<string, true> $slugsOfBatch
     */
    private function uniqueSlug(string $name, string $seriesId, array $slugsOfBatch): string
    {
        $base = (string) $this->slugger->slug(strtolower($name));
        $base = $base !== '' ? $base : 'edition';
        $slug = $base;
        $suffix = 1;

        while (isset($slugsOfBatch[$slug]) || $this->slugGenerator->isTaken($slug, $seriesId)) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
