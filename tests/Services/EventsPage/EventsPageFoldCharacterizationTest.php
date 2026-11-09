<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Tests\Services\EventDetail\PathUrlGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\EventType;
use SpeedPuzzling\Web\Value\EventTypes;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The events page folds the same texts over and over while it builds (a series' name for every edition, the country
 * names of every row, organization names): whatever remembers those folds, the page must come out byte for byte the
 * same - the shipped search index (`x`), the places (a city left out when it holds the country's name, localised or
 * English) and the order of everything sorted by folded name. The expected output is a snapshot of the page as it was
 * built before the folds were remembered (events-page-fold-snapshot.txt).
 */
final class EventsPageFoldCharacterizationTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/events-page-fold-snapshot.txt';

    public function testThePageAndItsIndexComeOutTheSame(): void
    {
        $built = '';

        foreach (['en', 'cs', 'de', 'ja'] as $locale) {
            foreach ([EventsScope::everywhere(), EventsScope::fromQuery('cz', null)] as $scope) {
                $page = self::build($locale, $scope);
                $built .= "== {$locale} " . $scope->key() . "\n"
                    . json_encode(EventsIndexExamples::factory()->compact($page->index), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
                    // The whole page (rows, places, order) as a hash - the snapshot file stays small
                    . 'page sha1 ' . sha1(serialize($page)) . "\n";
            }
        }

        foreach (self::places() as [$isOnline, $location, $country, $locale]) {
            $place = EventRowFactory::place($isOnline, $location, $country, $locale);
            $built .= json_encode([$location, $locale, $place->city, $place->country, $place->countryCode?->name, $place->isOnline], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        if (is_file(self::SNAPSHOT) === false) {
            file_put_contents(self::SNAPSHOT, $built);
            self::markTestIncomplete('Snapshot written - run again.');
        }

        self::assertSame(file_get_contents(self::SNAPSHOT), $built);
    }

    /**
     * @return list<array{bool, null|string, null|CountryCode, string}>
     */
    private static function places(): array
    {
        $places = [];

        foreach (['en', 'cs', 'de', 'fr', 'es', 'ja'] as $locale) {
            foreach ([
                [false, 'Praha, Czech Republic', CountryCode::cz],
                [false, 'Brno - Česko', CountryCode::cz],
                [false, 'Brno - CESKO', CountryCode::cz],
                [false, 'Ostrava', CountryCode::cz],
                [false, 'Wien, Österreich', CountryCode::at],
                [false, 'Vienna Austria', CountryCode::at],
                [false, '  Łódź, Polska ', CountryCode::pl],
                [false, 'Kraków', CountryCode::pl],
                [false, 'Zürich, Schweiz', CountryCode::ch],
                [false, 'Tokyo 日本', CountryCode::jp],
                [false, 'US Open hall, Denver', CountryCode::us],
                [false, '', CountryCode::de],
                [false, 'Berlin', null],
                [true, 'Praha', CountryCode::cz],
            ] as [$isOnline, $location, $country]) {
                $places[] = [$isOnline, $location, $country, $locale];
            }
        }

        return $places;
    }

    private static function build(string $locale, EventsScope $scope): \SpeedPuzzling\Web\Results\EventsPage\EventsPage
    {
        $utc = new DateTimeZone('UTC');
        $club = new OrganizationRef('018d0099-0000-0000-0000-0000000000c1', 'Česká puzzle asociace', 'ČPA', 'ceska-puzzle-asociace', true);
        $hiddenClub = new OrganizationRef('018d0099-0000-0000-0000-0000000000c2', 'Łódzki Klub Układania', null, 'lodzki-klub', false);

        $series = [
            new EventSeriesRow('018d0099-0000-0000-0000-0000000000b1', 'Pražský puzzle maraton', 'prazsky-puzzle-maraton', false, 'Praha, Czech Republic', CountryCode::cz, organization: $club),
            new EventSeriesRow('018d0099-0000-0000-0000-0000000000b2', "Where's the Edge? Weekly", 'wheres-the-edge', true),
            new EventSeriesRow('018d0099-0000-0000-0000-0000000000b3', 'Łódź Jigsaw Nights', 'lodz-jigsaw-nights', false, 'Łódź, Polska', CountryCode::pl, organization: $hiddenClub),
            new EventSeriesRow('018d0099-0000-0000-0000-0000000000b4', 'Alpine Cup', 'alpine-cup', false, 'Wien', CountryCode::at),
            new EventSeriesRow('018d0099-0000-0000-0000-0000000000b5', 'alpine cup junior', 'alpine-cup-junior', false, 'Innsbruck', CountryCode::at),
        ];

        $occurrences = [];
        $days = [-40, -12, -3, 0, 2, 9, 16, 30, 45, 75, 120, 400];

        foreach ($days as $i => $offset) {
            foreach ($series as $s => $row) {
                if (($i + $s) % 3 === 2) {
                    continue;
                }

                $day = new DateTimeImmutable(EventsIndexExamples::NOW, $utc)->setTime(0, 0)->modify(sprintf('%+d days', $offset + $s));
                $id = sprintf('018d0099-0000-7000-8%03d-%012d', $s, $i);
                $locations = [$row->location, 'Brno - Česko', 'Vienna Austria', null, '  Łódź, Polska '];

                $occurrences[] = new EventOccurrence(
                    competitionId: $id,
                    name: $i % 2 === 0 ? $row->name . ' ' . (2026 + intdiv($offset, 365)) : 'Edition ' . ($i + 1),
                    slug: 'edition-' . $s . '-' . $i,
                    seriesId: $row->id,
                    seriesName: $row->name,
                    seriesSlug: $row->slug,
                    location: $row->isOnline ? null : $locations[($i + $s) % count($locations)],
                    countryCode: $row->isOnline ? null : $row->countryCode,
                    isOnline: $row->isOnline,
                    startDate: $day,
                    endDate: $i % 4 === 0 ? $day->modify('+1 day') : null,
                    hasResults: $offset < 0,
                    isPublic: $i % 7 !== 5,
                    rounds: [new OccurrenceRound(
                        sprintf('018d0099-0000-7000-9%03d-%012d', $s, $i),
                        $i % 2 === 0 ? 'Solo' : 'Páry',
                        $day->modify('+17 hours'),
                        'Europe/Prague',
                        category: ($i % 2 === 0 ? RoundCategory::Solo : RoundCategory::Duo)->value,
                        puzzleNames: ['Kouzelná zahrada', "Where's Wally? Ｎｏ.５", 'Zauberhafter Garten'],
                    )],
                    organization: $row->organization,
                    eventTypes: $s === 3 ? EventTypes::of([EventType::Workshop, EventType::Competition]) : null,
                );
            }
        }

        foreach ([
            ['Mistrovství ČR', 'Ostrava', CountryCode::cz, 20],
            ['mistrovstvi cr', 'Olomouc, Česko', CountryCode::cz, 21],
            ['Alpine Cup', 'Zürich, Schweiz', CountryCode::ch, 5],
            ['Café Puzzle Évening', 'Paris', CountryCode::fr, -5],
            ['日本パズル大会', 'Tokyo 日本', CountryCode::jp, 60],
            ['US Open', 'US Open hall, Denver', CountryCode::us, 33],
        ] as $j => [$name, $location, $country, $offset]) {
            $day = new DateTimeImmutable(EventsIndexExamples::NOW, $utc)->setTime(0, 0)->modify(sprintf('%+d days', $offset));
            $occurrences[] = new EventOccurrence(
                competitionId: sprintf('018d0099-0000-7000-a000-%012d', $j),
                name: $name,
                slug: 'one-time-' . $j,
                location: $location,
                countryCode: $country,
                startDate: $day,
                organization: $j % 2 === 0 ? $club : null,
            );
        }

        usort($occurrences, static fn (EventOccurrence $a, EventOccurrence $b): int => [$a->startDate, $a->competitionId] <=> [$b->startDate, $b->competitionId]);

        return new EventsPageBuilder(new EventUrls(new PathUrlGenerator()), EventsIndexExamples::factory())->build(
            $occurrences,
            $series,
            [],
            null,
            $scope,
            new DateTimeImmutable(EventsIndexExamples::NOW, $utc),
            $locale,
            null,
        );
    }
}
