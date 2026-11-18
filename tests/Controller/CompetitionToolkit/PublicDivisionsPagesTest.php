<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions\DivisionsSetup;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public side of divisions (shared-puzzles-and-divisions.md D5) on St. Paul's divisions (DivisionsSetup): Group A
 * (solo, published) - Anna Regular 1st, Ben and Cara Senior 55+ tied 2nd overall, Dan Senior unfinished 4th, Eva Junior
 * did not start. The round page's division labels, chips and `?division=` filter (ranks, counts - A12 -, a hidden player
 * keeps everybody's ranks - A13), the competitor page's "Senior 55+ · 1st", the tags' division label. A round without
 * divisions stays as it was (OfficialRoundResultsGoldenTest, CompetitionNameTagsGoldenTest).
 */
final class PublicDivisionsPagesTest extends WebTestCase
{
    use DivisionsSetup;

    private const string GROUP_A = '/en/events/results-cup/results/group-a';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
    }

    public function testTheRoundPageLabelsEveryEntryWithItsDivisionAndRankWithinIt(): void
    {
        $page = $this->browser->request('GET', self::GROUP_A);
        self::assertResponseIsSuccessful();

        self::assertSame([
            F::ENTRY_A_ANNA => ['1', $this->regular, '1', 'Regular · 1st'],
            F::ENTRY_A_BEN => ['2', $this->senior, '1', 'S · 1st'],
            F::ENTRY_A_CARA => ['2', $this->senior, '1', 'S · 1st'],
            F::ENTRY_A_DAN => ['4', $this->senior, '3', 'S · 3rd'],
        ], $this->rows($page));
        self::assertSame('Senior 55+', $page->filter('[data-official-entry="participant_round:' . F::ENTRY_A_BEN . '"] [data-official-division-badge]')->attr('title'));

        // The chips: All (chosen), then the round's divisions in the event's order - a short label in the URL when there is one
        self::assertSame(['All', 'Regular', 'Senior 55+', 'Junior U16'], $page->filter('[data-official-division-chip]')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertSame('page', $page->filter('[data-official-division-chip="all"]')->attr('aria-current'));
        self::assertSame(self::GROUP_A, $page->filter('[data-official-division-chip="all"]')->attr('href'));
        self::assertSame(self::GROUP_A . '?division=' . $this->regular, $page->filter('[data-official-division-chip="' . $this->regular . '"]')->attr('href'));
        self::assertSame(self::GROUP_A . '?division=S', $page->filter('[data-official-division-chip="' . $this->senior . '"]')->attr('href'));
        self::assertCount(0, $page->filter('[data-official-division-count]'));
        self::assertStringContainsString('4 entrants', (string) $page->filter('meta[name="description"]')->attr('content'));
    }

    public function testTheFilterShowsOneDivisionWithItsRanksAndCount(): void
    {
        $page = $this->browser->request('GET', self::GROUP_A . '?division=s');
        self::assertResponseIsSuccessful();

        self::assertSame([F::ENTRY_A_BEN, F::ENTRY_A_CARA, F::ENTRY_A_DAN], array_keys($this->rows($page)));
        // The division rank is the big number, the overall place small under it
        self::assertSame(['1', '1', '3'], $page->filter('[data-official-entry] .lb-rank')->each(static fn (Crawler $rank): string => trim($rank->text())));
        self::assertSame(['2', '2', '4'], $page->filter('[data-official-overall-rank]')->each(static fn (Crawler $rank): string => (string) $rank->attr('data-official-overall-rank')));
        self::assertCount(0, $page->filter('[data-official-division-badge]'));
        self::assertSame('3', $page->filter('[data-official-division-count]')->attr('data-official-division-count'));
        self::assertStringContainsString('3 ranked in Senior 55+', $page->filter('[data-official-division-count]')->text());
        self::assertSame('page', $page->filter('[data-official-division-chip="' . $this->senior . '"]')->attr('aria-current'));
        self::assertStringContainsString('3 entrants', (string) $page->filter('meta[name="description"]')->attr('content'));

        // By id and by name too
        foreach ([$this->regular, 'regular', 'REGULAR'] as $value) {
            $page = $this->browser->request('GET', self::GROUP_A . '?division=' . $value);
            self::assertSame([F::ENTRY_A_ANNA], array_keys($this->rows($page)), $value);
            self::assertSame('1', $page->filter('[data-official-division-count]')->attr('data-official-division-count'));
        }

        // A division nobody is ranked in (Eva did not start): nothing to show, 0 ranked - the chips stay
        $page = $this->browser->request('GET', self::GROUP_A . '?division=J');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-official-entry]'));
        self::assertSame('0', $page->filter('[data-official-division-count]')->attr('data-official-division-count'));
        self::assertCount(4, $page->filter('[data-official-division-chip]'));

        // An unknown value: every entry, as without it
        $page = $this->browser->request('GET', self::GROUP_A . '?division=nope');
        self::assertCount(4, $page->filter('[data-official-entry]'));
        self::assertCount(0, $page->filter('[data-official-division-count]'));
    }

    public function testAHiddenPlayerKeepsEverybodysDivisionRanks(): void
    {
        // Ben (Senior, tied 1st in the division) is a player the viewer blocked
        $this->database->executeStatement(
            'UPDATE competition_participant SET player_id = :playerId, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'id' => F::PARTICIPANT_BEN],
        );
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $page = $this->browser->request('GET', self::GROUP_A . '?division=S');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ben Steady', (string) $this->browser->getResponse()->getContent());
        self::assertSame([F::ENTRY_A_CARA, F::ENTRY_A_DAN], array_keys($this->rows($page)));
        self::assertSame(['1', '3'], $page->filter('[data-official-entry] .lb-rank')->each(static fn (Crawler $rank): string => trim($rank->text())));
        // "N ranked" counts every ranked entry of the division, the hidden one too - like the overall count
        self::assertSame('3', $page->filter('[data-official-division-count]')->attr('data-official-division-count'));

        $page = $this->browser->request('GET', self::GROUP_A);
        self::assertSame([
            F::ENTRY_A_ANNA => ['1', $this->regular, '1', 'Regular · 1st'],
            F::ENTRY_A_CARA => ['2', $this->senior, '1', 'S · 1st'],
            F::ENTRY_A_DAN => ['4', $this->senior, '3', 'S · 3rd'],
        ], $this->rows($page));
    }

    public function testAPairRoundRanksThePairsWithinTheirDivisions(): void
    {
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => F::ROUND_PAIRS]);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SetRoundEntryDivisions(
            competitionId: F::COMPETITION_RESULTS_CUP,
            roundId: F::ROUND_PAIRS,
            entries: [
                new EntryDivisionInput(null, F::TEAM_SHARKS, 'S'),
                new EntryDivisionInput(null, F::TEAM_CORNERS, 'S'),
                new EntryDivisionInput(null, F::TEAM_EDGES, 'Regular'),
            ],
        ));

        $page = $this->browser->request('GET', '/en/events/results-cup/results/pairs');
        self::assertResponseIsSuccessful();
        self::assertSame([
            F::TEAM_SHARKS => ['1', $this->senior, '1', 'S · 1st'],
            F::TEAM_EDGES => ['2', $this->regular, '1', 'Regular · 1st'],
            F::TEAM_CORNERS => ['3', $this->senior, '2', 'S · 2nd'],
        ], $this->rows($page));
        // Pairs uses Regular + Senior only
        self::assertSame(['All', 'Regular', 'Senior 55+'], $page->filter('[data-official-division-chip]')->each(static fn (Crawler $chip): string => trim($chip->text())));
    }

    public function testARoundWithoutDivisionsIgnoresTheFilter(): void
    {
        // The Final uses Senior only but has nothing ranked - Group B uses none: publish it and compare
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => F::ROUND_GROUP_B]);

        $plain = $this->browser->request('GET', '/en/events/results-cup/results/group-b');
        self::assertResponseIsSuccessful();
        $filtered = $this->browser->request('GET', '/en/events/results-cup/results/group-b?division=S');
        self::assertResponseIsSuccessful();

        self::assertSame($plain->filter('[data-official-results]')->outerHtml(), $filtered->filter('[data-official-results]')->outerHtml());
        self::assertCount(0, $filtered->filter('[data-official-divisions], [data-official-division-badge], [data-official-division]'));
    }

    public function testTheCompetitorPageShowsTheDivisionAndItsPlace(): void
    {
        $this->database->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        foreach ([F::PARTICIPANT_ANNA => 'Regular · 1st', F::PARTICIPANT_BEN => 'Senior 55+ · 1st', F::PARTICIPANT_DAN => 'Senior 55+ · 3rd'] as $participant => $expected) {
            $page = $this->browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . $participant);
            self::assertResponseIsSuccessful();
            $groupA = $page->filter('[data-competitor-round="' . F::ROUND_GROUP_A . '"]');
            self::assertSame($expected, trim($groupA->filter('[data-competitor-division]')->text()), $participant);
        }

        $this->browser->request('GET', '/competitor-state/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_DAN);
        self::assertResponseIsSuccessful();
        $state = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('"division":{"id":"' . $this->senior . '","name":"Senior 55+","shortName":"S"},"divisionRank":3', $state);

        // Eva did not start: no result shown, so no division place either; the Final is not published
        $page = $this->browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_EVA);
        self::assertCount(0, $page->filter('[data-competitor-division]'));
        $page = $this->browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertCount(0, $page->filter('[data-competitor-round="' . F::ROUND_FINAL . '"] [data-competitor-division]'));
    }

    public function testTagsAndTentsPrintTheDivisionLabel(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SetRoundEntryDivisions(
            competitionId: F::COMPETITION_RESULTS_CUP,
            roundId: F::ROUND_PAIRS,
            entries: [new EntryDivisionInput(null, F::TEAM_SHARKS, 'S')],
        ));
        $tags = '/en/name-tags/' . F::COMPETITION_RESULTS_CUP;
        $groupA = '&round=' . F::ROUND_GROUP_A;

        // The name tags of before (no toolkit kind): Group A's people - Filip has no division
        $page = $this->browser->request('GET', $tags . '?sort=name' . $groupA);
        self::assertResponseIsSuccessful();
        self::assertSame(['Regular', 'S', 'S', 'S', 'J'], $this->divisionLabels($page));

        foreach (['people', 'entries'] as $kind) {
            foreach (['tags', 'tents'] as $layout) {
                $page = $this->browser->request('GET', $tags . '?kind=' . $kind . '&layout=' . $layout . '&sort=name' . $groupA);
                self::assertResponseIsSuccessful();
                $labels = $this->divisionLabels($page);
                // A tent prints both faces
                self::assertSame($layout === 'tents' ? ['Regular', 'Regular', 'S', 'S', 'S', 'S', 'S', 'S', 'J', 'J'] : ['Regular', 'S', 'S', 'S', 'J'], $labels, $kind . ' ' . $layout);
            }
        }

        // A line-up: its pair's division in the printed round
        $page = $this->browser->request('GET', $tags . '?kind=lineups&layout=tags&round=' . F::ROUND_PAIRS);
        self::assertResponseIsSuccessful();
        self::assertSame(['S'], $this->divisionLabels($page));
        self::assertStringContainsString('Puzzle Sharks', (string) $page->filter('[data-tag-division]')->closest('[data-tag]')?->text());

        // Tables carry none
        $page = $this->browser->request('GET', $tags . '?kind=tables&layout=tags');
        self::assertCount(0, $page->filter('[data-tag-division]'));
    }

    /**
     * @return array<string, array{string, string, string, string}> entry id => overall rank, division id, division rank, badge
     */
    private function rows(Crawler $page): array
    {
        $rows = [];
        $page->filter('[data-official-entry]')->each(static function (Crawler $row) use (&$rows): void {
            $ref = (string) preg_replace('/^[a-z_]+:/', '', (string) $row->attr('data-official-entry'));
            $badge = $row->filter('[data-official-division-badge]');
            $rows[$ref] = [
                (string) $row->attr('data-official-rank'),
                (string) $row->attr('data-official-division'),
                (string) $row->attr('data-official-division-rank'),
                $badge->count() > 0 ? trim($badge->text()) : '',
            ];
        });

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function divisionLabels(Crawler $page): array
    {
        return $page->filter('[data-tag-division]')->each(static fn (Crawler $label): string => trim($label->text()));
    }
}
