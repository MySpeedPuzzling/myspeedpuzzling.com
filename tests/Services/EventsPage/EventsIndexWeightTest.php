<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventsPage\EventsPage;
use SpeedPuzzling\Web\Twig\JsonLdTwigExtension;

/**
 * The events page ships its search and calendar index in every page view (`<script data-events-index>`). A series of
 * weekly online contests has ~200 editions a year; they must not add more than WEIGHT_BUDGET bytes to the page
 * (docs/features/events-page/high-frequency-series.md P25 - ~400 B an edition before, ~80 KB per 200). Built in memory
 * (EventsIndexExamples::weeklySeriesPage(): 200 past editions, one solo round with one revealed puzzle each), encoded
 * like the page encodes it (`json_ld`). The failure message records raw and gzip sizes, before (the full entries) and
 * after (the compact ones the page ships).
 */
final class EventsIndexWeightTest extends TestCase
{
    public const int EDITIONS = 200;
    public const int WEIGHT_BUDGET = 40_000;

    public function testTwoHundredEditionsStayWithinTheBudget(): void
    {
        $series = EventsIndexExamples::weeklySeriesPage(0);
        $withEditions = EventsIndexExamples::weeklySeriesPage(self::EDITIONS);

        self::assertCount(self::EDITIONS + 1, $withEditions->shippedIndex);

        $added = self::added($series, $withEditions, shipped: true);
        $before = self::added($series, $withEditions, shipped: false);
        $report = sprintf(
            '%d editions add %d B raw / %d B gzip to the shipped index (%d B raw / %d B gzip an edition); the full entries would add %d B raw / %d B gzip',
            self::EDITIONS,
            $added['raw'],
            $added['gzip'],
            intdiv($added['raw'], self::EDITIONS),
            intdiv($added['gzip'], self::EDITIONS),
            $before['raw'],
            $before['gzip'],
        );

        self::assertLessThanOrEqual(self::WEIGHT_BUDGET, $added['raw'], $report);
        // The compact entries are what makes it fit - a regression back to full entries shows here first
        self::assertLessThan($before['raw'] / 2, $added['raw'], $report);
    }

    /**
     * A past edition's entry stays small: its own name, slug, day, status, results flag and own words, its puzzle's name
     * included (P25: ≤ 200 B)
     */
    public function testOnePastEditionEntryIsSmall(): void
    {
        $page = EventsIndexExamples::weeklySeriesPage(self::EDITIONS);
        $entry = $page->shippedIndex[100];

        self::assertSame(['id', 'k', 'en', 'sid', 'es', 'f', 'st', 'r', 'x'], array_keys($entry));
        self::assertLessThanOrEqual(200, strlen(JsonLdTwigExtension::encode($entry)), JsonLdTwigExtension::encode($entry));
    }

    /**
     * @return array{raw: int, gzip: int}
     */
    private static function added(EventsPage $without, EventsPage $with, bool $shipped): array
    {
        $encode = static fn (EventsPage $page): string => JsonLdTwigExtension::encode($shipped ? $page->shippedIndex : $page->index);
        $gzip = static fn (string $json): int => strlen((string) gzencode($json, 6));

        return [
            'raw' => strlen($encode($with)) - strlen($encode($without)),
            'gzip' => $gzip($encode($with)) - $gzip($encode($without)),
        ];
    }
}
