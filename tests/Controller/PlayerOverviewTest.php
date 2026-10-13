<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A player's profile is their Overview (docs/features/navigation README §4.7, PROF-03): personal bests per piece-count
 * preset, the headline numbers, the latest results with "All N results", achievements and About - no full results list
 * (that is the Results page), no chart or skill/rating cards (Statistics, PROF-04).
 *
 * One seeded player (isolated puzzles, so ranks and bests are exact): solo times in the 500, 750, 1000, 1500 and 2000+
 * presets with a faster time awaiting verification, a relax result, two equal times and a hidden picture; a pair and a
 * team result.
 */
final class PlayerOverviewTest extends WebTestCase
{
    use ComparisonSeeding;

    private string $subject = '';

    /** @var array<string, string> puzzle ids by role */
    private array $puzzles = [];

    public function testPersonalBestsPerPresetLinkTheFilteredResults(): void
    {
        $browser = $this->seeded();
        $crawler = $browser->request('GET', '/en/player-profile/' . $this->subject);
        self::assertResponseIsSuccessful();

        $bests = $crawler->filter('main [data-testid="personal-bests"]');
        self::assertCount(1, $bests);
        self::assertSame('Personal bests (solo)', trim($bests->filter('h2')->text()));

        // In the presets' order; 750 has only a time awaiting verification, 1000 only a relax result - neither is a best
        $rows = $bests->filter('tr[data-pieces]');
        self::assertSame(['500', '1500', '2000-'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-pieces')));

        $formatter = new PuzzlingTimeFormatter();
        $expected = [
            // The 500 time awaiting verification (1000 s, another puzzle) is no best - the fastest real one is
            '500' => ['500 pieces', 'Overview 500 fast', 1800],
            // Equal times: the earlier solve
            '1500' => ['1500 pieces', 'Overview 1500 earlier', 4000],
            '2000-' => ['2000+ pieces', 'Overview 3000', 9000],
        ];

        foreach ($expected as $preset => [$label, $puzzle, $seconds]) {
            $preset = (string) $preset;
            $row = $bests->filter('tr[data-pieces="' . $preset . '"]');
            self::assertSame($label, trim($row->filter('.pov-best-preset')->text()), $preset);
            self::assertSame($puzzle, trim($row->filter('a.ps-puzzle-name')->text()), $preset);
            self::assertSame('/en/puzzle/' . $this->puzzles[$puzzle], $row->filter('a.ps-puzzle-name')->attr('href'), $preset);

            // A tap on the row = this preset on Results, fastest first
            $time = $row->filter('a.ps-time-value[data-row-link-target="link"]');
            self::assertSame($formatter->formatTime($seconds), trim($time->text()), $preset);
            self::assertSame('/en/player-results/' . $this->subject . '?pieces=' . $preset . '&sort=fastest', $time->attr('href'), $preset);

            // Nobody else solved the seeded puzzles: first of one
            self::assertSame('Rank 1 of 1', trim($row->filter('.pr-chip-rank')->text()), $preset);
        }

        // A range preset names the puzzle's own piece count, a single count does not repeat it
        self::assertStringContainsString('3000', $bests->filter('tr[data-pieces="2000-"] .ps-puzzle-meta')->text());
        self::assertStringNotContainsString('pieces', $bests->filter('tr[data-pieces="500"] .ps-puzzle-meta')->text());

        // The picture the puzzle still hides is not shown
        self::assertStringNotContainsString('overview-hidden-box.jpg', (string) $browser->getResponse()->getContent());
    }

    public function testTheNumbersCountWhatResultsListsAndLinkIt(): void
    {
        $browser = $this->seeded();
        $crawler = $browser->request('GET', '/en/player-profile/' . $this->subject);

        // Solo: 8 puzzles (the times awaiting verification and the relax result are listed on Results, so they count);
        // one pair, one team; 9 + 1 + 1 results
        self::assertSame([
            'solo' => ['8', '/en/player-results/' . $this->subject],
            'duo' => ['1', '/en/player-results/' . $this->subject . '?category=duo'],
            'group' => ['1', '/en/player-results/' . $this->subject . '?category=group'],
            'every' => ['11', '/en/player-results/' . $this->subject . '?view=every'],
        ], $this->numbers($crawler));
        self::assertSame('Solo puzzles', trim($crawler->filter('[data-overview-number="solo"] .pov-number-label')->text()));
        self::assertSame('Puzzle in a pair', trim($crawler->filter('[data-overview-number="duo"] .pov-number-label')->text()));
    }

    public function testLatestResultsAndAllResults(): void
    {
        $browser = $this->seeded();
        $crawler = $browser->request('GET', '/en/player-profile/' . $this->subject);

        $latest = $crawler->filter('main [data-testid="latest-results"]');
        self::assertCount(1, $latest);
        // The 8 latest of 11 (PlayerOverview::LATEST_RESULTS) - every other result is on Results, newest first
        self::assertCount(8, $latest->filter('tr[id^="activity-player-"]'));
        $all = $latest->filter('a[data-testid="all-results"]');
        self::assertSame('All 11 results', trim($all->text()));
        self::assertSame('/en/player-results/' . $this->subject . '?view=every', $all->attr('href'));

        // No full list and no standing on the Overview: Results and Statistics have them (PROF-03, PROF-04)
        self::assertCount(0, $crawler->filter('[data-live-name-value="PlayerSolvedPuzzles"]'));
        self::assertCount(0, $crawler->filter('[data-live-name-value="Chart:PlayerAverageTimeChart"], .profile-standing'));
        self::assertCount(0, $crawler->filter('main .nav-tabs'));
    }

    public function testAPlayerWithEightResultsOrFewerHasNoAllResultsLink(): void
    {
        $browser = self::createClient();
        $player = $this->seedPlayer('Overview Few');
        $manufacturer = $this->seedManufacturer('Overview Few Brand');

        for ($i = 1; $i <= 3; $i++) {
            $this->seedTime($player, $this->seedPuzzle(500, 'Overview few ' . $i, $manufacturer), 2000 + $i, new DateTimeImmutable('-' . $i . ' days'));
        }

        $crawler = $browser->request('GET', '/en/player-profile/' . $player);

        self::assertCount(3, $crawler->filter('[data-testid="latest-results"] tr[id^="activity-player-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="all-results"]'));
        self::assertSame('3', $this->numbers($crawler)['every'][0]);
    }

    /**
     * A relax-only player: no best for a visitor (nothing to show), the empty state for the owner; their result still
     * counts
     */
    public function testARelaxOnlyPlayerHasNoBests(): void
    {
        $browser = self::createClient();
        $player = $this->seedPlayer('Overview Relaxed', userId: 'overview-relaxed');
        $this->seedTime($player, $this->seedPuzzle(500, 'Overview relax', $this->seedManufacturer('Overview Relax Brand')), null, new DateTimeImmutable('-2 days'));

        $crawler = $browser->request('GET', '/en/player-profile/' . $player);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="personal-bests"]'));
        self::assertSame('1', $this->numbers($crawler)['solo'][0]);

        TestingLogin::asPlayer($browser, $player);
        $crawler = $browser->request('GET', '/en/player-profile/' . $player);
        $bests = $crawler->filter('[data-testid="personal-bests"]');
        self::assertCount(1, $bests);
        self::assertCount(0, $bests->filter('tr'));
        self::assertStringContainsString('once you add a timed solo result', $bests->filter('.pov-empty')->text());
    }

    public function testAPlayerWithoutResults(): void
    {
        $browser = self::createClient();
        $player = $this->seedPlayer('Overview Empty');

        $crawler = $browser->request('GET', '/en/player-profile/' . $player);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="personal-bests"], [data-testid="overview-numbers"]'));
        self::assertSame('No results here yet.', trim($crawler->filter('[data-testid="latest-results"] .pov-empty')->text()));
    }

    public function testAHiddenPrivateProfileIsTheHeaderAndTheNoticeOnly(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main .alert .bi-incognito'));
        self::assertCount(0, $crawler->filter('[data-testid="personal-bests"], [data-testid="overview-numbers"], [data-testid="latest-results"], [data-testid="about"]'));
        self::assertCount(0, $crawler->filter('[data-live-name-value="RecentActivity"]'));
    }

    /**
     * About: what was the "Personal info" tab - a visitor sees the filled fields only, the owner every field
     */
    public function testAboutShowsTheFilledFieldsAndTheOwnerEveryField(): void
    {
        $browser = self::createClient();
        $player = $this->seedPlayer('Overview About', userId: 'overview-about');
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET bio = 'Corners first.', city = 'Overview City' WHERE id = :id",
            ['id' => $player],
        );

        $crawler = $browser->request('GET', '/en/player-profile/' . $player);
        $about = $crawler->filter('[data-testid="about"]');
        self::assertSame('About', trim($about->filter('h2')->text()));
        self::assertSame(['About me', 'Location'], $about->filter('dt')->each(static fn (Crawler $dt): string => trim($dt->text())));
        self::assertStringContainsString('Corners first.', $about->text());
        self::assertStringNotContainsString('Not filled', $about->text());

        TestingLogin::asPlayer($browser, $player);
        $crawler = $browser->request('GET', '/en/player-profile/' . $player);
        $about = $crawler->filter('[data-testid="about"]');
        self::assertSame(['About me', 'Location', 'Nationality', 'Facebook', 'Instagram'], $about->filter('dt')->each(static fn (Crawler $dt): string => trim($dt->text())));
        self::assertStringContainsString('Not filled', $about->text());

        // Nothing filled: no section for a visitor
        $crawler = $browser->request('GET', '/en/player-profile/' . $this->seedPlayer('Overview No About'));
        self::assertCount(0, $crawler->filter('[data-testid="about"]'));
    }

    private function seeded(): KernelBrowser
    {
        $browser = self::createClient();
        $this->subject = $this->seedPlayer('Overview Subject');
        $partner = $this->seedPlayer('Overview Partner');
        $manufacturer = $this->seedManufacturer('Overview Brand');

        foreach (
            [
            'Overview 500 fast' => 500,
            'Overview 500 slow' => 500,
            'Overview 500 suspicious' => 500,
            'Overview 750' => 750,
            'Overview 1000 relax' => 1000,
            'Overview 1500 earlier' => 1500,
            'Overview 1500 later' => 1500,
            ] as $name => $pieces
        ) {
            $this->puzzles[$name] = $this->seedPuzzle($pieces, $name, $manufacturer);
        }

        $this->puzzles['Overview 3000'] = $this->seedPuzzle(3000, 'Overview 3000', $manufacturer, hideImageUntil: new DateTimeImmutable('+1 year'), image: 'overview-hidden-box.jpg');

        $day = static fn (int $daysAgo): DateTimeImmutable => new DateTimeImmutable('-' . $daysAgo . ' days');
        $this->seedTime($this->subject, $this->puzzles['Overview 500 fast'], 1800, $day(10));
        $this->seedTime($this->subject, $this->puzzles['Overview 500 fast'], 2100, $day(20));
        $this->seedTime($this->subject, $this->puzzles['Overview 500 slow'], 1900, $day(9));
        $this->seedTime($this->subject, $this->puzzles['Overview 500 suspicious'], 1000, $day(8), suspicious: true);
        $this->seedTime($this->subject, $this->puzzles['Overview 750'], 2500, $day(7), suspicious: true);
        $this->seedTime($this->subject, $this->puzzles['Overview 1000 relax'], null, $day(6));
        $this->seedTime($this->subject, $this->puzzles['Overview 1500 earlier'], 4000, $day(5));
        $this->seedTime($this->subject, $this->puzzles['Overview 1500 later'], 4000, $day(4));
        $this->seedTime($this->subject, $this->puzzles['Overview 3000'], 9000, $day(3));

        $pair = $this->seedTeam([$this->subject, $partner]);
        $this->seedTime($this->subject, $this->puzzles['Overview 500 fast'], 1200, $day(2), teamId: $pair);
        $team = $this->seedTeam([$this->subject, $partner], ['Overview Guest']);
        $this->seedTime($partner, $this->puzzles['Overview 1000 relax'], 3000, $day(1), teamId: $team);

        return $browser;
    }

    /**
     * @return array<string, array{string, null|string}> number id => [value, link]
     */
    private function numbers(Crawler $crawler): array
    {
        $numbers = [];

        $crawler->filter('[data-testid="overview-numbers"] [data-overview-number]')->each(static function (Crawler $number) use (&$numbers): void {
            $numbers[(string) $number->attr('data-overview-number')] = [trim($number->filter('.pov-number-value')->text()), $number->attr('href')];
        });

        return $numbers;
    }
}
