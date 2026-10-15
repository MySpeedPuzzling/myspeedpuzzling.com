<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The Hub's "most active puzzlers" loads lazily: its placeholder draws `showLimit` rows and the "Show more" button. The
 * rendered list keeps exactly that shape whatever the period holds - fewer puzzlers leave empty slots (hidden from
 * screen readers, showing nothing: no rank number, no dash - QA-04 R18) and the button's room - so the swap moves
 * nothing below it: the Hub at 360 px loads without a layout shift (docs/features/navigation GEST-16,
 * checks/GEST-16.py; a month with one puzzler moved the next section 262 px).
 */
final class MostActiveSoloPlayersShapeTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /**
     * The Hub's 4 rows (the fixtures have more puzzlers in every period: the button), and 30 rows - more than the list's
     * 20 puzzlers (the empty slots and the button's room)
     *
     * @return iterable<string, array{string, int}>
     */
    public static function shapes(): iterable
    {
        foreach (['this_month', 'last_month', 'all_time'] as $timespan) {
            yield $timespan . ', 4 rows' => [$timespan, 4];
            yield $timespan . ', 30 rows' => [$timespan, 30];
        }
    }

    #[DataProvider('shapes')]
    public function testTheRenderedListHasThePlaceholdersShape(string $timespan, int $showLimit): void
    {
        self::createClient();
        $crawler = $this->createLiveComponent('MostActiveSoloPlayers', ['timespan' => $timespan, 'showLimit' => $showLimit])->render()->crawler();

        $shown = $crawler->filter('table tbody tr')->reduce(static fn (Crawler $row): bool => !str_contains((string) $row->attr('class'), 'd-none'));
        self::assertCount($showLimit, $shown, $timespan . ': as many rows as the placeholder draws');

        // The empty slots fill the rows after the last puzzler - each as tall as a puzzler's row (the same cells), showing
        // nothing: no rank, no name, no dash (QA-04 R18)
        $empty = $crawler->filter('table tbody tr.lb-row-empty');
        $puzzlers = $crawler->filter('table tbody tr:not(.lb-row-empty)')->count();
        self::assertCount(max(0, $showLimit - $puzzlers), $empty, $timespan);
        self::assertSame([], $crawler->filter('table tbody tr.lb-row-empty ~ tr:not(.lb-row-empty)')->each(static fn (Crawler $row): string => (string) $row->text()), $timespan . ': the slots come last');

        if ($showLimit === 30) {
            self::assertGreaterThan(0, $empty->count(), $timespan . ': the list holds at most 20 puzzlers');
        }

        self::assertSame(array_fill(0, $empty->count(), 'true'), $empty->each(static fn (Crawler $row): string => (string) $row->attr('aria-hidden')));
        self::assertSame(array_fill(0, $empty->count(), ''), $empty->each(static fn (Crawler $row): string => trim(str_replace("\u{a0}", ' ', $row->text()))), $timespan . ': an empty slot shows nothing');
        self::assertSame(array_fill(0, $empty->count(), [1, 1, 1]), $empty->each(static fn (Crawler $row): array => [
            $row->filter('td.rank .lb-rank')->count(),
            $row->filter('td.lb-solver .lb-avatar')->count(),
            $row->filter('td.lb-time .lb-time-value')->count(),
        ]), $timespan . ': the cells of a puzzler\'s row - its height');

        // The button, or its room (invisible, out of the Tab order and the accessibility tree)
        $buttons = $crawler->filter('button.btn-sm.mt-3');
        self::assertCount(1, $buttons, $timespan);

        // The real button where rows are left to show, else its room
        $hidden = $crawler->filter('table tbody tr.d-none')->count();
        self::assertSame($hidden === 0, str_contains((string) $buttons->attr('class'), 'invisible'), $timespan);

        if ($hidden === 0) {
            self::assertSame('true', $buttons->attr('aria-hidden'));
            self::assertSame('-1', $buttons->attr('tabindex'));
        }
    }
}
