<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The Hub's "most active puzzlers" loads lazily: its placeholder draws `showLimit` rows and the "Show more" button. The
 * rendered list keeps exactly that shape whatever the period holds - fewer puzzlers leave numbered empty slots (hidden
 * from screen readers) and the button's room - so the swap moves nothing below it: the Hub at 360 px loads without a
 * layout shift (docs/features/navigation GEST-16, checks/GEST-16.py; a month with one puzzler moved the next section
 * 262 px).
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

        // The empty slots continue the ranks after the last puzzler
        $empty = $crawler->filter('table tbody tr.lb-row-empty');
        $ranks = $empty->each(static fn (Crawler $row): int => (int) $row->filter('.lb-rank')->text());
        self::assertSame($ranks === [] ? [] : range($showLimit - count($ranks) + 1, $showLimit), $ranks, $timespan);

        if ($showLimit === 30) {
            self::assertNotSame([], $ranks, $timespan . ': the list holds at most 20 puzzlers');
        }
        self::assertSame(array_fill(0, count($ranks), 'true'), $empty->each(static fn (Crawler $row): string => (string) $row->attr('aria-hidden')));

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
