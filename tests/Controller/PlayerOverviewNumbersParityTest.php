<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Overview's numbers (GetPlayerOverviewNumbers, docs/features/navigation PROF-03) are the counts of the Results tabs
 * they link - for the same viewer, read from both rendered pages: Solo / Pairs / Teams = the By-puzzle tabs, Results =
 * the three Every-result tabs together. The history holds what could make them differ: results awaiting verification
 * (listed on Results, so counted), a relax result, a puzzle solved twice, a pair with a member the viewer blocked (gone
 * from both for that viewer only), a team with a guest.
 */
final class PlayerOverviewNumbersParityTest extends WebTestCase
{
    use ComparisonSeeding;

    private const string BLOCKER = PlayerFixture::PLAYER_WITH_FAVORITES;

    /**
     * @return iterable<string, array{string, array{solo: int, duo: int, group: int, every: int}}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => ['guest', ['solo' => 5, 'duo' => 2, 'group' => 1, 'every' => 10]];
        // The pair with the member he blocked is gone - from the Overview and from Results alike
        yield 'a viewer who blocked a partner' => ['blocker', ['solo' => 5, 'duo' => 1, 'group' => 1, 'every' => 9]];
        // The player's own history stays whole (HiddenPlayers keeps results the viewer took part in)
        yield 'the player' => ['owner', ['solo' => 5, 'duo' => 2, 'group' => 1, 'every' => 10]];
    }

    /**
     * @param array{solo: int, duo: int, group: int, every: int} $expected
     */
    #[DataProvider('provideViewers')]
    public function testTheNumbersAreTheResultsTabsCounts(string $viewer, array $expected): void
    {
        $browser = self::createClient();
        $subject = $this->seedHistory();

        match ($viewer) {
            'blocker' => TestingLogin::asPlayer($browser, self::BLOCKER),
            'owner' => TestingLogin::asPlayer($browser, $subject),
            default => null,
        };

        $overview = $browser->request('GET', '/en/player-profile/' . $subject);
        self::assertResponseIsSuccessful();
        $numbers = [];

        foreach (['solo', 'duo', 'group', 'every'] as $id) {
            $numbers[$id] = (int) $overview->filter('[data-testid="overview-numbers"] [data-overview-number="' . $id . '"] .pov-number-value')->text();
        }

        self::assertSame($expected, $numbers, 'The seeded history, as the Overview counts it');

        $byPuzzle = $this->tabCounts($browser->request('GET', '/en/player-results/' . $subject));
        self::assertSame($numbers['solo'], $byPuzzle['solo'], 'Solo');
        self::assertSame($numbers['duo'], $byPuzzle['duo'], 'Pairs');
        self::assertSame($numbers['group'], $byPuzzle['group'], 'Teams');

        self::assertSame($numbers['every'], array_sum($this->tabCounts($browser->request('GET', '/en/player-results/' . $subject . '?view=every'))), 'Every result');

        // Each number opens its tab
        $soloLink = (string) $overview->filter('[data-overview-number="solo"]')->attr('href');
        self::assertSame($numbers['solo'], $this->tabCounts($browser->request('GET', $soloLink))['solo']);
        $everyLink = (string) $overview->filter('[data-overview-number="every"]')->attr('href');
        self::assertSame($numbers['every'], array_sum($this->tabCounts($browser->request('GET', $everyLink))));
    }

    /**
     * A player without solo results: Results opens Pairs - the Pairs number links it without a category, Solo is no link
     */
    public function testThePairsNumberLinksTheDefaultTabOfAPlayerWithoutSoloResults(): void
    {
        $browser = self::createClient();
        $player = $this->seedPlayer('Parity Pairs Only');
        $pair = $this->seedTeam([$player, $this->seedPlayer('Parity Pairs Partner')]);
        $this->seedTime($player, $this->seedPuzzle(500, 'Parity pairs puzzle', $this->seedManufacturer('Parity Pairs Brand')), 1500, new DateTimeImmutable('-1 day'), teamId: $pair);

        $overview = $browser->request('GET', '/en/player-profile/' . $player);

        self::assertSame('/en/player-results/' . $player, $overview->filter('a[data-overview-number="duo"]')->attr('href'));
        self::assertCount(0, $overview->filter('a[data-overview-number="solo"]'));
        self::assertSame('0', trim($overview->filter('span[data-overview-number="solo"] .pov-number-value')->text()));

        $results = $browser->request('GET', '/en/player-results/' . $player);
        self::assertSame('duo', $results->filter('[data-results-category][aria-current="page"]')->attr('data-results-category'));
    }

    private function seedHistory(): string
    {
        $subject = $this->seedPlayer('Parity Subject', userId: 'parity-subject');
        $partner = $this->seedPlayer('Parity Partner');
        $blocked = $this->seedPlayer('Parity Blocked Partner');
        $this->seedBlock(self::BLOCKER, $blocked);

        $manufacturer = $this->seedManufacturer('Parity Brand');
        $puzzles = [];

        for ($i = 1; $i <= 5; $i++) {
            $puzzles[$i] = $this->seedPuzzle([500, 1000, 300, 750, 1500][$i - 1], 'Parity ' . $i, $manufacturer);
        }

        $day = static fn (int $daysAgo): DateTimeImmutable => new DateTimeImmutable('-' . $daysAgo . ' days');

        // Solo: 5 puzzles, 7 results - one solved twice, one awaiting verification, one relax, one both
        $this->seedTime($subject, $puzzles[1], 1800, $day(20));
        $this->seedTime($subject, $puzzles[1], 1700, $day(19));
        $this->seedTime($subject, $puzzles[2], 3600, $day(18), suspicious: true);
        $this->seedTime($subject, $puzzles[3], null, $day(17));
        $this->seedTime($subject, $puzzles[4], 2400, $day(16));
        $this->seedTime($subject, $puzzles[5], 5000, $day(15));
        $this->seedTime($subject, $puzzles[5], null, $day(14), suspicious: true);

        // Pairs: one with the partner, one with the member the blocker blocked (tracked by that member)
        $this->seedTime($subject, $puzzles[1], 1200, $day(10), teamId: $this->seedTeam([$subject, $partner]));
        $this->seedTime($blocked, $puzzles[2], 2000, $day(9), teamId: $this->seedTeam([$blocked, $subject]));

        // A team with a guest
        $this->seedTime($partner, $puzzles[3], 900, $day(8), teamId: $this->seedTeam([$partner, $subject], ['Parity Guest']));

        return $subject;
    }

    /**
     * The counts on the Results page's Solo / Pairs / Teams tabs
     *
     * @return array{solo: int, duo: int, group: int}
     */
    private function tabCounts(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();
        $count = static function (string $category) use ($crawler): int {
            $tab = $crawler->filter('[data-testid="results-categories"] [data-results-category="' . $category . '"]');
            self::assertCount(1, $tab, $category);
            self::assertSame(1, preg_match('/\((\d+)\)\s*$/', trim($tab->text()), $match), $tab->text());

            return (int) $match[1];
        };

        return ['solo' => $count('solo'), 'duo' => $count('duo'), 'group' => $count('group')];
    }
}
