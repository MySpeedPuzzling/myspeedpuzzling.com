<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The Charts tab as the compare page renders it (docs/features/player-comparison.md "Charts"): a card per chart with
 * title, takeaway, the chart with its aria summary and an HTML legend; by difficulty with the pair's head to head per
 * tier; the head-to-head grid only with 3+ subjects.
 */
final class ComparisonChartsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const string ME = '018d0000-0000-0000-0000-00000000000a';
    private const string ANNA = '018d0000-0000-0000-0000-00000000000b';
    private const string BEN = '018d0000-0000-0000-0000-00000000000c';

    public function testSixCardsForALineUpOfThree(): void
    {
        $crawler = $this->render([self::ME, self::ANNA, self::BEN]);

        $cards = $crawler->filter('figure.cmp-chart');
        self::assertSame(
            ['comparison-chart-lead_lag', 'comparison-chart-scatter', 'comparison-chart-pace', 'comparison-chart-difficulty', 'comparison-chart-form', 'comparison-chart-matrix'],
            $cards->each(static fn(Crawler $card): string => (string) $card->attr('data-testid')),
        );
        self::assertSame(
            ['Who\'s ahead, puzzle by puzzle', 'Your time vs Ben Berger\'s', 'Pace by piece count', 'By difficulty', 'Form over time', 'Head to head'],
            $cards->filter('h2')->each(static fn(Crawler $title): string => trim($title->text())),
        );
        self::assertCount(6, $crawler->filter('[data-testid="comparison-chart-takeaway"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-chart-note"]'));

        // Five Chart.js charts, each an image with a summary, wired to the comparison-chart controller
        $canvases = $crawler->filter('[data-controller="comparison-chart"] canvas[role="img"]');
        self::assertCount(5, $canvases);

        foreach ($canvases->each(static fn(Crawler $canvas): string => (string) $canvas->attr('aria-label')) as $summary) {
            self::assertNotSame('', $summary);
        }

        self::assertStringContainsString('between You and Ben Berger', (string) $canvases->first()->attr('aria-label'));

        // An HTML legend under every chart: A coral, B indigo, the rest gray
        $legends = $crawler->filter('ul[data-testid="comparison-chart-legend"]');
        self::assertCount(5, $legends);
        self::assertSame(['You faster', 'Ben Berger faster'], $legends->eq(0)->filter('li')->each(static fn(Crawler $item): string => trim($item->text())));
        self::assertSame(['You', 'Ben Berger', 'Rest of the line-up'], $legends->eq(2)->filter('li')->each(static fn(Crawler $item): string => trim($item->text())));
        self::assertStringContainsString('#fe4042', (string) $legends->eq(0)->filter('i')->eq(0)->attr('style'));
        self::assertStringContainsString('#4e54c8', (string) $legends->eq(0)->filter('i')->eq(1)->attr('style'));

        // Head to head: a real table, read across
        $grid = $crawler->filter('[data-testid="comparison-h2h"]');
        self::assertCount(1, $grid);
        self::assertStringContainsString('Read across', $grid->filter('caption')->text());
        self::assertCount(3, $grid->filter('tbody tr'));
        self::assertCount(3, $grid->filter('td.cmp-h2h-self'));
        self::assertSame(['You', 'Anna Novak', 'Ben Berger'], $grid->filter('tbody th')->each(static fn(Crawler $name): string => trim($name->text())));
        self::assertCount(1, $crawler->filter('.cmp-h2h-ramp'));

        self::assertStringContainsString('Charts use the same filters as the list.', $crawler->text());
    }

    public function testByDifficultyForMembersWithRatedPuzzles(): void
    {
        $crawler = $this->render([self::ME, self::ANNA, self::BEN]);
        $card = $crawler->filter('[data-testid="comparison-chart-difficulty"]');

        // My best pace against the line-up is on Easy (median -2.4 % vs -2.3 % on Hard), Ben's on Hard
        self::assertSame('You\'re strongest on Easy puzzles; Ben Berger on Hard', trim($card->filter('[data-testid="comparison-chart-takeaway"]')->text()));
        self::assertStringContainsString('per difficulty (Easy, Hard)', (string) $card->filter('canvas')->attr('aria-label'));
        self::assertSame(['You', 'Ben Berger', 'Rest of the line-up'], $card->filter('ul[data-testid="comparison-chart-legend"] li')->each(static fn(Crawler $item): string => trim($item->text())));

        // The pair's head to head per tier: the site's difficulty icons, one sentence per row for screen readers
        $h2h = $card->filter('[data-testid="comparison-difficulty-h2h"]');
        self::assertCount(1, $h2h);
        self::assertSame('Head to head: You vs Ben Berger', trim($h2h->filter('.cmp-tier-h2h-title')->text()));
        $rows = $h2h->filter('[data-testid="comparison-difficulty-h2h-row"]');
        self::assertCount(2, $rows);
        self::assertSame(
            ['/difficulty-icons-sprite.svg#diff-easy', '/difficulty-icons-sprite.svg#diff-hard'],
            $rows->filter('svg.diff-icon use')->each(static fn(Crawler $use): string => (string) $use->attr('href')),
        );
        self::assertSame(['Easy: You 1 – 1 Ben Berger', 'Hard: You 1 – 1 Ben Berger'], $rows->filter('th .visually-hidden')->each(static fn(Crawler $text): string => trim($text->text())));
        self::assertSame('Hard: You 1 – 1 Ben Berger', $rows->eq(1)->attr('title'));
        self::assertSame(['1', '1'], $rows->eq(1)->filter('.cmp-tier-h2h-num')->each(static fn(Crawler $number): string => trim($number->text())));
        self::assertCount(0, $rows->eq(1)->filter('.is-ahead'), 'Level: neither number stands out');
        self::assertSame('flex: 1', $rows->eq(1)->filter('.cmp-tier-h2h-a')->attr('style'));
        self::assertSame('flex: 1', $rows->eq(1)->filter('.cmp-tier-h2h-b')->attr('style'));

        // Me vs Anna: I won both Hard ones - my number stands out, the bar is all mine
        $hard = $this->render([self::ME, self::ANNA])->filter('[data-testid="comparison-difficulty-h2h-row"]')->eq(1);
        self::assertSame('Hard: You 2 – 0 Anna Novak', $hard->attr('title'));
        self::assertSame('2', trim($hard->filter('.cmp-tier-h2h-num.is-ahead')->text()));
        self::assertCount(1, $hard->filter('.cmp-tier-h2h-a'));
        self::assertCount(0, $hard->filter('.cmp-tier-h2h-b'));
    }

    public function testByDifficultyWithoutRatedPuzzlesIsTooThin(): void
    {
        $crawler = $this->render([self::ME, self::ANNA], tiers: false);
        $card = $crawler->filter('[data-testid="comparison-chart-difficulty"]');

        self::assertSame('By difficulty', trim($card->filter('h2')->text()));
        self::assertCount(1, $card->filter('[data-testid="comparison-chart-note"]'));
        self::assertCount(0, $card->filter('canvas'));
        self::assertCount(0, $card->filter('[data-testid="comparison-difficulty-h2h"]'));
    }

    public function testADuelHasNoHeadToHeadGrid(): void
    {
        $crawler = $this->render([self::ME, self::ANNA]);

        self::assertCount(5, $crawler->filter('figure.cmp-chart'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-chart-matrix"]'));
    }

    public function testTooLittleDataShowsANoteInsteadOfTheChart(): void
    {
        $crawler = $this->render([self::ME, self::ANNA], puzzles: 2);

        self::assertCount(5, $crawler->filter('[data-testid="comparison-chart-note"]'));
        self::assertCount(0, $crawler->filter('canvas'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-chart-legend"]'));
    }

    public function testNothingWithoutAResult(): void
    {
        $crawler = $this->renderTwigComponent('ComparisonCharts', ['result' => null, 'subjects' => []])->crawler();

        self::assertCount(0, $crawler->filter('figure'));
        self::assertStringNotContainsString('Charts use the same filters', $crawler->text(''));
    }

    /**
     * Everybody solved the first $puzzles of four puzzles: two Easy ones of 500 pieces two months ago, two Hard ones of
     * 1000 this month ($tiers false: not rated yet).
     *
     * @param list<string> $playerIds ME is "you"
     */
    private function render(array $playerIds, int $puzzles = 4, bool $tiers = true): Crawler
    {
        [$result, $subjects] = $this->comparison($playerIds, $puzzles, $tiers);

        return $this->renderTwigComponent('ComparisonCharts', ['result' => $result, 'subjects' => $subjects])->crawler();
    }

    /**
     * @param list<string> $playerIds
     * @return array{ComparisonResult, list<ComparisonSubject>}
     */
    private function comparison(array $playerIds, int $puzzles, bool $tiers): array
    {
        $names = [self::ME => 'Me Myself', self::ANNA => 'Anna Novak', self::BEN => 'Ben Berger'];
        $earlier = (new DateTimeImmutable('first day of this month'))->modify('-2 months')->setTime(12, 0);
        $recent = (new DateTimeImmutable('first day of this month'))->setTime(12, 0);
        $times = [
            ['p1', 500, $earlier, 2, [self::ME => 900, self::ANNA => 1000, self::BEN => 1100]],
            ['p2', 500, $earlier, 2, [self::ME => 1000, self::ANNA => 900, self::BEN => 950]],
            ['p3', 1000, $recent, 5, [self::ME => 2000, self::ANNA => 2100, self::BEN => 1900]],
            ['p4', 1000, $recent, 5, [self::ME => 2100, self::ANNA => 2200, self::BEN => 2300]],
        ];
        $rows = [];

        foreach (array_slice($times, 0, $puzzles) as [$puzzleId, $pieces, $day, $tier, $seconds]) {
            foreach ($playerIds as $playerId) {
                $rows[] = new ComparisonTimeRow(
                    subject: ComparisonSubjectRef::player($playerId),
                    puzzleId: $puzzleId,
                    piecesCount: $pieces,
                    attempts: 1,
                    bestSeconds: $seconds[$playerId],
                    bestTimeId: $playerId . '-' . $puzzleId,
                    bestDay: $day,
                    firstTrySeconds: null,
                    firstTryTimeId: null,
                    firstTryDay: null,
                    difficultyTier: $tiers ? $tier : null,
                    puzzleName: 'Puzzle ' . $puzzleId,
                );
            }
        }

        $subjects = array_map(static fn(string $playerId): ComparisonSubject => new ComparisonSubject(
            ref: ComparisonSubjectRef::player($playerId),
            kind: ComparisonKind::Solo,
            isAvailable: true,
            isViewer: $playerId === self::ME,
            playerName: $names[$playerId],
            playerCode: 'code' . substr($playerId, -1),
        ), $playerIds);

        $result = (new ComparisonBuilder())->build(
            $subjects,
            $rows,
            ComparisonCriteria::fromUserInput(subjectCount: count($subjects), isMember: true, show: 'all'),
        );

        return [$result, $subjects];
    }
}
