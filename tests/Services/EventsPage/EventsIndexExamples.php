<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\EventsPage;
use SpeedPuzzling\Web\Services\EventsPage\EventsIndexFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Tests\Services\EventDetail\PathUrlGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Events pages built in memory for the index tests (EventsIndexFactoryTest, EventsIndexScriptTest,
 * EventsIndexWeightTest) - made-up series only, no database.
 */
final class EventsIndexExamples
{
    public const string NOW = '2026-10-15 10:00';

    public static function factory(): EventsIndexFactory
    {
        return new EventsIndexFactory(new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return $id === 'events_page.place.online' ? 'Online' : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        });
    }

    /**
     * Two series, an edition of each (one held elsewhere than its series), a one-time event
     */
    public static function smallPage(): EventsPage
    {
        $utc = new DateTimeZone('UTC');
        $lantern = new EventSeriesRow('018d0099-0000-0000-0000-000000000030', 'Lantern Weekly Jam', 'lantern-weekly-jam', true);
        $harbor = new EventSeriesRow('018d0099-0000-0000-0000-000000000031', 'Harbor Jigsaw Nights', 'harbor-jigsaw-nights', false, 'Harbor Town', CountryCode::cz);

        $jam = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000032',
            name: 'Jam No. 153',
            slug: 'jam-no-153',
            seriesId: $lantern->id,
            seriesName: $lantern->name,
            seriesSlug: $lantern->slug,
            isOnline: true,
            startDate: new DateTimeImmutable('2026-10-05', $utc),
            hasResults: true,
            rounds: [new OccurrenceRound('018d0099-0000-0000-0000-000000000033', 'Solo', new DateTimeImmutable('2026-10-05 17:00', $utc), 'Europe/Berlin', category: 'solo', puzzleNames: ['Copper Lighthouse'])],
        );
        $special = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000034',
            name: 'Harbor Special',
            slug: 'harbor-special',
            seriesId: $harbor->id,
            seriesName: $harbor->name,
            seriesSlug: $harbor->slug,
            location: 'Innsbruck',
            countryCode: CountryCode::at,
            startDate: new DateTimeImmutable('2026-10-10', $utc),
        );
        $open = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000035',
            name: 'Riverside Puzzle Open',
            slug: 'riverside-puzzle-open',
            location: 'Riverside',
            countryCode: CountryCode::us,
            startDate: new DateTimeImmutable('2026-10-20', $utc),
        );

        return self::build([$jam, $special, $open], [$lantern, $harbor]);
    }

    /**
     * One online series with $editions weekly-ish editions (two a week, ending a week before NOW, so most are past),
     * each one solo round with one revealed 500-piece puzzle - the high-frequency series of
     * docs/features/events-page/high-frequency-series.md. Zero editions: the series alone.
     */
    public static function weeklySeriesPage(int $editions, int $upcoming = 0): EventsPage
    {
        $utc = new DateTimeZone('UTC');
        $series = new EventSeriesRow('018d0099-0000-0000-0000-0000000000a0', 'Lantern Weekly Jam', 'lantern-weekly-jam', true);
        $occurrences = [];
        $first = new DateTimeImmutable('2026-10-08', $utc);

        for ($i = 0; $i < $editions + $upcoming; $i++) {
            $number = $i + 1;
            // Past ones counted back from a week before NOW, every 3-4 days; upcoming ones after NOW
            $day = $i < $editions
                ? $first->modify(sprintf('-%d days', (int) round(($editions - 1 - $i) * 3.5)))
                : $first->modify(sprintf('+%d days', 10 + 3 * ($i - $editions)));
            $id = sprintf('018d0099-0000-7000-8000-%012d', $number);

            $occurrences[] = new EventOccurrence(
                competitionId: $id,
                name: sprintf('Jam No. %d', $number),
                slug: sprintf('jam-no-%d', $number),
                seriesId: $series->id,
                seriesName: $series->name,
                seriesSlug: $series->slug,
                isOnline: true,
                startDate: $day,
                hasResults: $i < $editions,
                firstRound: null,
                rounds: [new OccurrenceRound(
                    sprintf('018d0099-0000-7000-9000-%012d', $number),
                    'Solo',
                    $day->modify('+17 hours'),
                    'Europe/Berlin',
                    category: RoundCategory::Solo->value,
                    puzzleNames: [self::puzzleName($number)],
                )],
            );
        }

        return self::build($occurrences, [$series]);
    }

    /**
     * Made-up puzzle names, about as long as real ones
     */
    private static function puzzleName(int $number): string
    {
        $first = ['Copper', 'Starry', 'Velvet', 'Amber', 'Misty', 'Golden', 'Silent', 'Painted', 'Hidden', 'Winter'];
        $second = ['Lighthouse', 'Harbor', 'Meadow', 'Garden Path', 'Mountain Lake', 'Old Town', 'Market Square', 'Forest Cabin'];

        return $first[$number % count($first)] . ' ' . $second[intdiv($number, count($first)) % count($second)];
    }

    /**
     * @param list<EventOccurrence> $occurrences
     * @param list<EventSeriesRow> $series
     */
    private static function build(array $occurrences, array $series): EventsPage
    {
        usort($occurrences, static fn (EventOccurrence $a, EventOccurrence $b): int => $a->startDate <=> $b->startDate);

        return new EventsPageBuilder(new EventUrls(new PathUrlGenerator()), self::factory())->build(
            $occurrences,
            $series,
            [],
            null,
            EventsScope::everywhere(),
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
            'en',
            null,
        );
    }
}
