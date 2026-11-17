<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The public competitor page and its state JSON (docs/features/competitions-management/competitor-pages.md, toolkit
 * B4) on the "Results Cup" fixture: Group A published (Anna 1st, Ben and Cara tied 2nd, Dan unfinished, Eva did not
 * start), Group B not published (Gina - a private player -, Hugo - PLAYER_REGULAR -, Ivan), the Final (Anna, no
 * result), Pairs not published (Puzzle Sharks = Anna + Ben, Corner Pieces, Edge Hunters = Gina + Hugo).
 */
final class CompetitorPageControllerTest extends WebTestCase
{
    private const string CUP = F::COMPETITION_RESULTS_CUP;
    private const string UNKNOWN = '018d0020-0000-0000-0000-00000000ffff';

    public function testNothingExistsWhileCompetitorPagesAreOff(): void
    {
        $browser = self::createClient();

        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA);
            self::assertResponseStatusCodeSame(404, 'organisers too: nothing public by accident');
            $browser->request('GET', '/competitor-state/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testASoloPersonPageListsTheirRoundsWithTheOfficialPlaceOfPublishedOnesOnly(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        self::assertStringContainsString('Anna Fast', $crawler->filter('[data-competitor-header]')->text());
        // Linked to the admin's public profile: its code and link
        self::assertSame('#ADMIN', $crawler->filter('[data-competitor-code]')->text());

        // Schedule order: Group A (09:00), Pairs (13:00), Final (15:00)
        self::assertSame([F::ROUND_GROUP_A, F::ROUND_PAIRS, F::ROUND_FINAL], $this->roundIds($crawler));

        $groupA = $this->round($crawler, F::ROUND_GROUP_A);
        self::assertSame('official', $groupA->filter('[data-competitor-result]')->attr('data-competitor-result'));
        self::assertSame('1', $groupA->filter('[data-competitor-rank]')->text());
        self::assertStringContainsString('1:00:00', $groupA->filter('[data-competitor-result]')->text());
        self::assertCount(1, $groupA->filter('[data-competitor-qualified]'));
        self::assertStringContainsString('Table 1', $groupA->filter('[data-competitor-table]')->text());
        self::assertStringContainsString('Puzzle 10', $groupA->filter('[data-competitor-puzzles]')->text());

        // Pairs is not published: the pair is named, its result is not
        $pairs = $this->round($crawler, F::ROUND_PAIRS);
        self::assertCount(0, $pairs->filter('[data-competitor-result]'));
        self::assertStringContainsString('Puzzle Sharks', $pairs->filter('[data-competitor-entrant]')->text());
        self::assertStringContainsString('Ben Steady', $pairs->filter('[data-competitor-entrant]')->text());

        self::assertCount(0, $this->round($crawler, F::ROUND_FINAL)->filter('[data-competitor-result]'));
    }

    public function testThePlacesAreTheRoundPagesOnesTiesIncluded(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $roundPage = $browser->request('GET', '/en/events/results-cup/results/group-a');
        $official = [];
        $roundPage->filter('[data-official-entry]')->each(static function (Crawler $row) use (&$official): void {
            $official[(string) $row->attr('data-official-entry')] = (string) $row->attr('data-official-rank');
        });

        foreach ([F::PARTICIPANT_ANNA => F::ENTRY_A_ANNA, F::PARTICIPANT_BEN => F::ENTRY_A_BEN, F::PARTICIPANT_CARA => F::ENTRY_A_CARA, F::PARTICIPANT_DAN => F::ENTRY_A_DAN] as $participant => $entry) {
            $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . $participant);
            self::assertSame($official['participant_round:' . $entry], $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-rank]')->text(), $participant);
        }

        // Did not start: official, without a place
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_EVA);
        $groupA = $this->round($crawler, F::ROUND_GROUP_A);
        self::assertStringContainsString('Did not start', $groupA->filter('[data-competitor-result]')->text());
        self::assertCount(0, $groupA->filter('[data-competitor-rank]'));
    }

    public function testBeforePublishingTheResultShowsOnlyWithTheLiveResultsSettingAndNeverWithAPlace(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $url = '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_IVAN;

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-competitor-result]'));
        self::assertStringNotContainsString('1:23:20', (string) $browser->getResponse()->getContent());

        $this->turnOn($browser, liveResults: true);
        $crawler = $browser->request('GET', $url);
        $result = $this->round($crawler, F::ROUND_GROUP_B)->filter('[data-competitor-result]');
        self::assertSame('live', $result->attr('data-competitor-result'));
        self::assertStringContainsString('1:23:20', $result->text());
        self::assertCount(0, $crawler->filter('[data-competitor-rank]'), 'never a place before publishing');
        self::assertCount(0, $crawler->filter('[data-competitor-qualified]'));
    }

    public function testTheCompetitorSeesTheirOwnConfirmedTimeBeforePublishing(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $url = '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_HUGO;

        // Somebody else: nothing
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', $url);
        self::assertCount(0, $crawler->filter('[data-competitor-result]'));
        self::assertCount(0, $crawler->filter('[data-competitor-own]'));

        // Hugo himself (PLAYER_REGULAR): his own time, no place - in his pair round too, which has a result
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $url);
        self::assertCount(1, $crawler->filter('[data-competitor-own]'));
        $groupB = $this->round($crawler, F::ROUND_GROUP_B)->filter('[data-competitor-result]');
        self::assertSame('own', $groupB->attr('data-competitor-result'));
        self::assertStringContainsString('1:15:00', $groupB->text());
        self::assertSame('own', $this->round($crawler, F::ROUND_PAIRS)->filter('[data-competitor-result]')->attr('data-competitor-result'));
        self::assertCount(0, $crawler->filter('[data-competitor-rank]'));

        // The state JSON says the same, and names his entry - his own only
        $browser->request('GET', '/competitor-state/' . self::CUP . '/p/' . F::PARTICIPANT_HUGO);
        $state = $this->json($browser);
        $round = $this->stateRound($state, F::ROUND_GROUP_B);
        self::assertSame('own', self::at($round, 'entry.result.state'));
        self::assertSame(4500, self::at($round, 'entry.result.seconds'));
        self::assertNull(self::at($round, 'entry.result.rank'));
        self::assertSame('participant_round:' . F::ENTRY_B_HUGO, self::at($round, 'entry.entry'));
    }

    public function testAPairLineUpPage(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $sharks = CompetitionLineupId::of(self::CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/g/' . $sharks);
        self::assertResponseIsSuccessful();
        self::assertSame('Puzzle Sharks', $crawler->filter('[data-competitor-team-name]')->text());
        self::assertCount(2, $crawler->filter('[data-competitor-person]'));
        self::assertSame([F::ROUND_PAIRS], $this->roundIds($crawler));
        self::assertCount(0, $crawler->filter('[data-competitor-result]'), 'Pairs is not published');

        // Published: the pair's official place
        $this->publish($browser, F::ROUND_PAIRS);
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/g/' . $sharks);
        self::assertSame('1', $crawler->filter('[data-competitor-rank]')->text());
        self::assertStringContainsString('1:30:00', $crawler->filter('[data-competitor-result]')->text());

        // Ben as a member sees it as his page
        $this->link($browser, F::PARTICIPANT_BEN, PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/g/' . $sharks);
        self::assertCount(1, $crawler->filter('[data-competitor-own]'));
    }

    public function testATablePageShowsWhoeverSitsThereThisRoundFirst(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        // Table 1: Anna in Group A, Gina in Group B, the Puzzle Sharks in Pairs - nothing runs (10 days ago), so the
        // current round is the last one played: Pairs
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/t/1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Table 1', $crawler->filter('h1')->text());
        self::assertSame([F::ROUND_PAIRS, F::ROUND_GROUP_A, F::ROUND_GROUP_B], $this->roundIds($crawler));
        self::assertCount(1, $this->round($crawler, F::ROUND_PAIRS)->filter('[data-competitor-round-current]'));
        self::assertStringContainsString('Puzzle Sharks', $this->round($crawler, F::ROUND_PAIRS)->filter('[data-competitor-entrant]')->text());
        self::assertStringContainsString('Anna Fast', $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-entrant]')->text());
        self::assertSame('1', $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-rank]')->text());
        // Gina is a private player: the organiser's name, nothing of her profile
        $gina = $this->round($crawler, F::ROUND_GROUP_B)->filter('[data-competitor-entrant]');
        self::assertStringContainsString('Gina Quick', $gina->text());
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());

        // A running round moves to the top
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = " . TestClock::SQL_NOW . " - INTERVAL '10 minutes' WHERE id = :id",
            ['id' => F::ROUND_GROUP_B],
        );
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/t/1');
        self::assertSame([F::ROUND_GROUP_B, F::ROUND_GROUP_A, F::ROUND_PAIRS], $this->roundIds($crawler));
        self::assertSame('running', $this->round($crawler, F::ROUND_GROUP_B)->attr('data-competitor-round-phase'));

        // A table nobody uses: the page, saying so
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/t/99');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-competitor-no-rounds]'));
    }

    public function testARoundEntryPageShowsThatRoundOnly(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/e/' . F::ENTRY_A_BEN);
        self::assertResponseIsSuccessful();
        self::assertSame([F::ROUND_GROUP_A], $this->roundIds($crawler));
        self::assertStringContainsString('Ben Steady', $crawler->filter('[data-competitor-header]')->text());
        self::assertSame('2', $crawler->filter('[data-competitor-rank]')->text());

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/e/' . F::TEAM_CORNERS);
        self::assertResponseIsSuccessful();
        self::assertSame('Corner Pieces', $crawler->filter('[data-competitor-team-name]')->text());
        self::assertSame([F::ROUND_PAIRS], $this->roundIds($crawler));

        // A pair member's own row stands for the pair
        $memberRow = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_participant_round WHERE team_id = :team LIMIT 1',
            ['team' => F::TEAM_CORNERS],
        );
        self::assertIsString($memberRow);
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/e/' . $memberRow);
        self::assertSame('Corner Pieces', $crawler->filter('[data-competitor-team-name]')->text());
    }

    public function testUnknownBrokenAndForeignTagsAreNotFound(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $cup = self::CUP;

        foreach (
            [
                '/en/competitor/' . $cup . '/p/' . self::UNKNOWN,
                '/en/competitor/' . $cup . '/p/' . CompetitionParticipantFixture::PARTICIPANT_CONNECTED,
                '/en/competitor/' . $cup . '/g/' . self::UNKNOWN,
                '/en/competitor/' . $cup . '/e/' . self::UNKNOWN,
                '/en/competitor/' . $cup . '/t/' . F::PARTICIPANT_ANNA,
                '/en/competitor/' . $cup . '/p/12',
                '/en/competitor/' . $cup . '/x/12',
                '/en/competitor/' . $cup . '/t/0',
                '/en/competitor/' . $cup . '/t/10000',
                '/en/competitor/' . self::UNKNOWN . '/t/1',
                '/en/competitor/not-an-id/t/1',
                '/competitor-state/' . $cup . '/g/' . self::UNKNOWN,
            ] as $path
        ) {
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }

        // Somebody removed from the event is nobody's page
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => F::PARTICIPANT_IVAN],
        );
        $browser->request('GET', '/en/competitor/' . $cup . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseStatusCodeSame(404);
    }

    public function testADraftIsTheTeamsOnly(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::CUP]);
        $url = '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA;

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testABlockedPlayersPageIsNotThereAndATableMasksThem(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $database = $browser->getContainer()->get(Connection::class);
        // Anna is linked to the admin; PLAYER_REGULAR blocks the admin
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/en/competitor/' . self::CUP . '/e/' . F::ENTRY_A_ANNA);
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/en/competitor/' . self::CUP . '/g/' . CompetitionLineupId::of(self::CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]));
        self::assertResponseStatusCodeSame(404);

        // Ben's page: his pair round with Anna is masked, his solo round is there
        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_BEN);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->round($crawler, F::ROUND_PAIRS)->filter('[data-competitor-masked]'));
        self::assertSame('2', $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-rank]')->text());

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/t/1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-masked]'));
        self::assertCount(0, $this->round($crawler, F::ROUND_GROUP_A)->filter('[data-competitor-result]'));
        self::assertStringNotContainsString('Anna Fast', (string) $browser->getResponse()->getContent());
    }

    public function testAHiddenRoundPuzzleIsNowhereOnThePageNorInTheState(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $database = $browser->getContainer()->get(Connection::class);
        // The Final's puzzle ("Puzzle 18") is a secret until the organiser reveals it
        $database->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, reveal_mode = 'manual', reveal_at = NULL WHERE round_id = :round",
            ['round' => F::ROUND_FINAL],
        );
        $roundPuzzleId = self::string($database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => F::ROUND_FINAL]));

        foreach (['/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA, '/competitor-state/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA] as $url) {
            foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
                $browser->restart();
                if ($viewer !== null) {
                    TestingLogin::asPlayer($browser, $viewer);
                }
                $browser->request('GET', $url);
                self::assertResponseIsSuccessful();
                $content = (string) $browser->getResponse()->getContent();
                self::assertStringNotContainsString('Puzzle 18', $content, $url . ' - organisers neither');
                self::assertStringNotContainsString(\SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture::PUZZLE_6000, $content, $url);
                self::assertStringNotContainsString(\SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture::EAN_PUZZLE_6000, $content, $url);
                self::assertStringNotContainsString($roundPuzzleId, $content, $url);
                // Canary: the revealed puzzle of Group A is there
                self::assertStringContainsString('Puzzle 10', $content, $url);
            }
        }

        // A scheduled reveal a second ago: there
        $database->executeStatement(
            "UPDATE competition_round_puzzle SET reveal_mode = 'scheduled', reveal_at = " . TestClock::SQL_NOW . " - INTERVAL '1 second' WHERE round_id = :round",
            ['round' => F::ROUND_FINAL],
        );
        $browser->request('GET', '/competitor-state/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertStringContainsString('Puzzle 18', (string) $browser->getResponse()->getContent());
    }

    public function testAPickRoundShowsItsPlacesByLabelAndTheChosenPuzzle(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser, liveResults: true);
        $database = $browser->getContainer()->get(Connection::class);
        $roundPuzzleId = self::string($database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => F::ROUND_GROUP_B]));
        // Group B: pick 1 of 2 - its puzzle ("Puzzle 17") and a second, secret one ("Puzzle 18", manual reveal)
        $secretId = Uuid::uuid7()->toString();
        $database->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, reveal_mode) VALUES (:id, :round, :puzzle, true, 'manual')",
            ['id' => $secretId, 'round' => F::ROUND_GROUP_B, 'puzzle' => \SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture::PUZZLE_6000],
        );
        $database->executeStatement("UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 1 WHERE id = :id", ['id' => F::ROUND_GROUP_B]);
        $database->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE id = :id',
            ['puzzles' => json_encode([['roundPuzzleId' => $roundPuzzleId, 'done' => true]], JSON_THROW_ON_ERROR), 'id' => F::ENTRY_B_IVAN],
        );

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseIsSuccessful();
        $groupB = $this->round($crawler, F::ROUND_GROUP_B);
        self::assertStringContainsString('Pick 1 of 2 puzzles', $groupB->filter('[data-competitor-pick]')->text());
        self::assertCount(1, $groupB->filter('[data-competitor-puzzle-hidden]'));
        self::assertStringContainsString('Puzzle 17', $groupB->filter('[data-competitor-chips]')->text());
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Puzzle 18', $content);
        self::assertStringNotContainsString($secretId, $content);

        $browser->request('GET', '/competitor-state/' . self::CUP . '/p/' . F::PARTICIPANT_IVAN);
        $round = $this->stateRound($this->json($browser), F::ROUND_GROUP_B);
        self::assertSame(['mode' => 'pick', 'toSolve' => 1], self::at($round, 'puzzleFormat'));
        self::assertStringNotContainsString('Puzzle 18', (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString($secretId, (string) $browser->getResponse()->getContent());
    }

    public function testTheStateJson(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $browser->request('GET', '/competitor-state/' . self::CUP . '/t/1');
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        $state = $this->json($browser);

        self::assertSame(1791979200000, self::at($state, 'serverNowMs'));
        self::assertSame('t', self::at($state, 'kind'));
        self::assertSame('1', self::at($state, 'id'));
        self::assertSame(['/round-stopwatch/' . F::ROUND_PAIRS, '/round-stopwatch/' . F::ROUND_GROUP_A, '/round-stopwatch/' . F::ROUND_GROUP_B], self::at($state, 'mercure.topics'));
        $groupA = $this->stateRound($state, F::ROUND_GROUP_A);
        self::assertSame(1, self::at($groupA, 'entry.result.rank'));
        self::assertSame('official', self::at($groupA, 'entry.result.state'));
        self::assertSame(['roundId', 'status', 'startedAt', 'stoppedAt', 'minutesLimit', 'startedAtMs', 'stoppedAtMs', 'asOfMs', 'stateHash'], array_keys(self::array(self::at($groupA, 'stopwatch'))));
        self::assertArrayNotHasKey('entry', self::array(self::at($groupA, 'entry')), 'which entry it is - the viewer\'s own only');
        // No player ids beyond the page (the page links public profiles; the JSON names people only)
        self::assertStringNotContainsString(PlayerFixture::PLAYER_ADMIN, (string) $browser->getResponse()->getContent());
        self::assertNull(self::at($this->stateRound($state, F::ROUND_GROUP_B), 'entry.result'));
    }

    public function testThePageCarriesTheLiveClockWithTheSameStateAsTheJson(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = " . TestClock::SQL_NOW . " - INTERVAL '10 minutes' WHERE id = :id",
            ['id' => F::ROUND_GROUP_B],
        );

        $crawler = $browser->request('GET', '/en/competitor/' . self::CUP . '/t/1');
        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-competitor-page]');
        self::assertSame('competitor-page', $root->attr('data-controller'));
        self::assertSame('/competitor-state/' . self::CUP . '/t/1', $root->attr('data-competitor-page-state-url-value'));

        /** @var array<string, mixed> $labels */
        $labels = json_decode((string) $root->attr('data-competitor-page-labels-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Starts in %time%', $labels['starts_in'] ?? null);
        self::assertSame("Time's up", $labels['times_up'] ?? null);
        self::assertSame('In progress', $labels['running'] ?? null);

        // The first state is the competitor_state JSON - the same view, nothing more (no hidden puzzle, no player id)
        /** @var array<string, mixed> $pageState */
        $pageState = json_decode((string) $root->attr('data-competitor-page-state-value'), true, flags: JSON_THROW_ON_ERROR);
        $browser->request('GET', '/competitor-state/' . self::CUP . '/t/1');
        $state = $this->json($browser);
        unset($state['mercure']);
        self::assertSame($state, $pageState);

        // Every card: a clock (hidden until the controller draws it), a badge and B8's empty slot
        foreach ([F::ROUND_PAIRS, F::ROUND_GROUP_A, F::ROUND_GROUP_B] as $roundId) {
            $card = $this->round($crawler, $roundId);
            self::assertCount(1, $card->filter('[data-competitor-clock][hidden] [data-competitor-clock-time]'));
            self::assertCount(1, $card->filter('[data-competitor-round-badge]'));
            self::assertCount(1, $card->filter('[data-competitor-round-actions]'));
        }
        self::assertSame('In progress', $this->round($crawler, F::ROUND_GROUP_B)->filter('[data-competitor-round-badge]')->text());
        self::assertCount(1, $crawler->filter('[data-competitor-rounds] [data-competitor-round]')->first());
    }

    private function turnOn(KernelBrowser $browser, bool $liveResults = false): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = true, competitor_pages_live_results = :live WHERE id = :id',
            ['id' => self::CUP, 'live' => $liveResults],
            ['live' => 'boolean'],
        );
    }

    private function publish(KernelBrowser $browser, string $roundId): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => $roundId],
        );
    }

    private function link(KernelBrowser $browser, string $participantId, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET player_id = :player, connected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['player' => $playerId, 'id' => $participantId],
        );
    }

    /**
     * @return list<string>
     */
    private function roundIds(Crawler $crawler): array
    {
        return $crawler->filter('[data-competitor-round]')->each(static fn (Crawler $round): string => (string) $round->attr('data-competitor-round'));
    }

    private function round(Crawler $crawler, string $roundId): Crawler
    {
        $round = $crawler->filter('[data-competitor-round="' . $roundId . '"]');
        self::assertCount(1, $round, 'round ' . $roundId);

        return $round;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        /** @var array<string, mixed> $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $json;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stateRound(array $state, string $roundId): array
    {
        /** @var list<array<string, mixed>> $rounds */
        $rounds = self::at($state, 'rounds');
        foreach ($rounds as $round) {
            if (self::at($round, 'roundId') === $roundId) {
                return $round;
            }
        }

        self::fail('No round ' . $roundId);
    }

    private static function at(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($data, $path);
            self::assertArrayHasKey($key, $data, $path);
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $data): array
    {
        self::assertIsArray($data);

        return $data;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
