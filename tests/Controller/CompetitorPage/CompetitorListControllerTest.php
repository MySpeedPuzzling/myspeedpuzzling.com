<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The public competitor list (docs/features/competitions-management/competitor-pages.md "Competitor list") on the
 * "Results Cup" fixture: nine people (Anna = the admin's public profile #ADMIN, Gina = a private player, Hugo =
 * PLAYER_REGULAR), four pairs in the Pairs round (Puzzle Sharks = Anna + Ben, Corner Pieces = Cara + Dan, an unnamed
 * pair Eva + Filip, Edge Hunters = Gina + Hugo).
 */
final class CompetitorListControllerTest extends WebTestCase
{
    private const string CUP = F::COMPETITION_RESULTS_CUP;
    private const string URL = '/en/competitor/' . self::CUP;

    public function testNothingExistsWhileCompetitorPagesAreOff(): void
    {
        $browser = self::createClient();

        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            $browser->request('GET', self::URL);
            self::assertResponseStatusCodeSame(404, 'organisers too: nothing public by accident');
        }

        $this->turnOn($browser);
        $browser->request('GET', '/en/competitor/018d0020-0000-0000-0000-00000000ffff');
        self::assertResponseStatusCodeSame(404, 'an unknown event');
    }

    public function testEverybodyGoingAndEveryPairIsListedByName(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $crawler->filter('link[rel="canonical"]'));

        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending', 'Gina Quick', 'Hugo Slow', 'Ivan Last'],
            $this->people($crawler),
        );
        self::assertSame(['Corner Pieces', 'Edge Hunters', 'Eva Noshow & Filip Pending', 'Puzzle Sharks'], $this->lineups($crawler));
        self::assertSame('13', $crawler->filter('[data-competitor-list-total]')->text());

        // A public profile's #code is shown and searchable; the private player is the organiser's name only
        $anna = $crawler->filter('[data-competitor-list-row="p"]')->first();
        self::assertSame('#ADMIN', $anna->filter('[data-competitor-list-code]')->text());
        self::assertSame('anna fast #admin', $anna->attr('data-search'));
        self::assertSame('/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_ANNA, $anna->filter('a')->attr('href'));
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('PLAYER2', strtoupper($content));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $content);

        // The pair's members on a second line, its link the line-up page
        $sharks = $crawler->filter('[data-competitor-list-row="g"]')->last();
        self::assertStringContainsString('Anna Fast & Ben Steady', $sharks->text());
        self::assertSame('/en/competitor/' . self::CUP . '/g/' . CompetitionLineupId::of(self::CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]), $sharks->filter('a')->attr('href'));

        // No avatars, no results, no puzzles - and no "You" for a guest
        $list = $crawler->filter('[data-competitor-list]');
        self::assertCount(0, $list->filter('img'));
        self::assertCount(0, $list->filter('[data-competitor-list-you]'));
        self::assertStringNotContainsString('Puzzle 10', $list->text());

        // Tables as their competitor pages show them (search by table): Anna at table 1 of Group A, her pair at table 1 of
        // Pairs; the unnamed pair has none - no table line, no hint
        self::assertSame('Table 1', $anna->filter('[data-competitor-list-tables]')->text());
        self::assertSame('1', $anna->filter('[data-competitor-list-table]')->attr('data-competitor-list-table'));
        self::assertSame('Table 1 · Group A', $anna->filter('[data-competitor-list-table]')->text());
        self::assertNotNull($anna->filter('[data-competitor-list-table]')->attr('hidden'), 'the hint shows only when its table matched');
        self::assertSame('Table 1 · Pairs', $sharks->filter('[data-competitor-list-table]')->text());
        $unnamed = $crawler->filter('[data-competitor-list-row="g"]')->eq(2);
        self::assertSame('Eva Noshow & Filip Pending', $unnamed->filter('[data-competitor-list-name]')->text());
        self::assertCount(0, $unnamed->filter('[data-competitor-list-tables], [data-competitor-list-table]'));
        self::assertSame('Search by name, #code or table', $crawler->filter('[data-competitor-list-target="search"]')->attr('placeholder'));

        // The search field is there for the browser (hidden until the controller connects), no notice without asking
        self::assertCount(1, $crawler->filter('[data-competitor-list-target="search"]'));
        self::assertCount(0, $crawler->filter('[data-competitor-list-notice]'));
    }

    public function testTableNamesAndEveryRoundOfATableAreSearchable(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $database = $browser->getContainer()->get(Connection::class);
        // Pairs named like a hall plan (Sharks 1, Corners 2, Edges 3), Anna kept at table 1 for the Final, Group B
        // without table numbers, a team name of an earlier round
        $database->executeStatement(
            'UPDATE competition_round SET table_labels = :labels WHERE id = :id',
            ['labels' => json_encode(['A1 L', 'A1 R', 'A10 L'], JSON_THROW_ON_ERROR), 'id' => F::ROUND_PAIRS],
        );
        $database->executeStatement('UPDATE competition_participant_round SET table_number = 1 WHERE id = :id', ['id' => F::ENTRY_FINAL_ANNA]);
        $database->executeStatement('UPDATE competition_round SET table_numbers_off = true WHERE id = :id', ['id' => F::ROUND_GROUP_B]);
        $finalSharks = Uuid::uuid7()->toString();
        $database->executeStatement(
            "INSERT INTO competition_team (id, round_id, name, table_number) VALUES (:id, :round, 'Final Sharks', 1)",
            ['id' => $finalSharks, 'round' => F::ROUND_PAIRS_FINAL],
        );
        foreach ([[F::PARTICIPANT_ANNA, $finalSharks, null], [F::PARTICIPANT_BEN, $finalSharks, null], [F::PARTICIPANT_IVAN, null, 2]] as [$participant, $team, $table]) {
            $database->executeStatement(
                'INSERT INTO competition_participant_round (id, participant_id, round_id, team_id, table_number) VALUES (:id, :participant, :round, :team, :table)',
                ['id' => Uuid::uuid7()->toString(), 'participant' => $participant, 'round' => F::ROUND_PAIRS_FINAL, 'team' => $team, 'table' => $table],
            );
        }

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $anna = $crawler->filter('[data-competitor-list-row="p"]')->first();
        self::assertSame('Table 1', $anna->filter('[data-competitor-list-tables]')->text());
        self::assertSame('Table 1 · Group A and 1 more round', $anna->filter('[data-competitor-list-table]')->text());

        // Group B's tables are off: Gina and Hugo have none (their pages show none either)
        foreach ([6, 7] as $index) {
            self::assertCount(0, $crawler->filter('[data-competitor-list-row="p"]')->eq($index)->filter('[data-competitor-list-tables]'));
        }

        // Ivan waits for a pair in the Pairs Final at table 2 - his page shows it, so does his row
        $ivan = $crawler->filter('[data-competitor-list-row="p"]')->eq(8);
        self::assertSame('Ivan Last', $ivan->filter('[data-competitor-list-name]')->text());
        self::assertSame('Table 2 · Pairs Final', $ivan->filter('[data-competitor-list-table]')->text());

        // The Sharks play the Pairs Final as "Final Sharks" at table 1: named by the latest round, both names searchable,
        // both tables listed
        $sharks = $crawler->filter('[data-competitor-list-row="g"]')->reduce(static fn (Crawler $row): bool => $row->filter('[data-competitor-list-name]')->text() === 'Final Sharks');
        self::assertCount(1, $sharks);
        self::assertSame('puzzle sharks final sharks anna fast ben steady', $sharks->attr('data-search'));
        self::assertSame('Tables A1 L, 1', $sharks->filter('[data-competitor-list-tables]')->text());
        self::assertSame(['Table A1 L · Pairs', 'Table 1 · Pairs Final'], $sharks->filter('[data-competitor-list-table]')->each(static fn (Crawler $hint): string => $hint->text()));

        $tables = [];
        $crawler->filter('[data-competitor-list-row="g"]')->each(static function (Crawler $row) use (&$tables): void {
            $hint = $row->filter('[data-competitor-list-table]');
            $tables[$row->filter('[data-competitor-list-name]')->text()] = $hint->count() > 0 ? [(string) $hint->attr('data-competitor-list-table'), $hint->text(), $row->filter('[data-competitor-list-tables]')->text()] : null;
        });
        self::assertSame([
            'Corner Pieces' => ['A.1.R', 'Table A1 R · Pairs', 'Table A1 R'],
            'Edge Hunters' => ['A.10.L', 'Table A10 L · Pairs', 'Table A10 L'],
            'Eva Noshow & Filip Pending' => null,
            'Final Sharks' => ['A.1.L', 'Table A1 L · Pairs', 'Tables A1 L, 1'],
        ], $tables);
    }

    public function testEveryListedRowOpensItsPage(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        // A member removed from the event: the pair is a line-up of the one left - still its page
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => F::PARTICIPANT_CARA],
        );

        $crawler = $browser->request('GET', self::URL);
        self::assertNotContains('Cara Tied', $this->people($crawler));
        self::assertContains('Corner Pieces', $this->lineups($crawler));
        $links = $crawler->filter('[data-competitor-list-row] a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertCount(12, $links);

        foreach ($links as $link) {
            $browser->request('GET', $link);
            self::assertResponseIsSuccessful($link);
        }
    }

    public function testTheWaitlistIsNotListedButKeepsItsPage(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id",
            ['id' => F::PARTICIPANT_IVAN],
        );

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertNotContains('Ivan Last', $this->people($crawler));
        self::assertCount(8, $this->people($crawler));

        $browser->request('GET', '/en/competitor/' . self::CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseIsSuccessful();
    }

    public function testAnEventWithNobodySaysSo(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE competition_id = :id',
            ['id' => self::CUP],
        );

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-competitor-list-empty]'));
        self::assertCount(0, $crawler->filter('[data-competitor-list-row]'));
        self::assertCount(0, $crawler->filter('[data-competitor-list-target="search"]'));
    }

    public function testAnUnknownTagsNoticeIsTheOnlyQueryValueRead(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);

        $crawler = $browser->request('GET', self::URL . '?notice=tag_unknown');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This tag is not in the event', $crawler->filter('[data-competitor-list-notice]')->text());

        foreach (['?notice=other', '?notice[]=tag_unknown', '?notice=<b>x</b>'] as $query) {
            $crawler = $browser->request('GET', self::URL . $query);
            self::assertResponseIsSuccessful($query);
            self::assertCount(0, $crawler->filter('[data-competitor-list-notice]'), $query);
        }
    }

    public function testADraftIsTheTeamsOnly(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::CUP]);

        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
    }

    public function testABlockedPlayerAndTheirPairAreNotListedButAMemberSeesTheirOwn(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        // Anna is linked to the admin; PLAYER_REGULAR (Hugo) blocks the admin
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertNotContains('Anna Fast', $this->people($crawler));
        self::assertNotContains('Puzzle Sharks', $this->lineups($crawler));
        self::assertContains('Ben Steady', $this->people($crawler));
        self::assertStringNotContainsString('Anna Fast', (string) $browser->getResponse()->getContent());
        // Hugo's own row and pair say "You"
        self::assertCount(2, $crawler->filter('[data-competitor-list-you]'));

        // The admin (Anna) sees herself and her pair - the group rule
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::URL);
        self::assertContains('Anna Fast', $this->people($crawler));
        self::assertContains('Puzzle Sharks', $this->lineups($crawler));
        self::assertCount(1, $crawler->filter('[data-competitor-list-row="p"]')->first()->filter('[data-competitor-list-you]'));
    }

    public function testTheEventAndTheCompetitorPagesLinkTheList(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/results-cup');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-event-competitors-link]'), 'competitor pages off: no link');

        $this->turnOn($browser);
        $crawler = $browser->request('GET', '/en/events/results-cup');
        self::assertSame(self::URL, $crawler->filter('[data-event-competitors-link]')->attr('href'));

        foreach (['/p/' . F::PARTICIPANT_ANNA, '/t/1', '/g/' . CompetitionLineupId::of(self::CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN])] as $page) {
            $crawler = $browser->request('GET', self::URL . $page);
            self::assertResponseIsSuccessful($page);
            self::assertSame(self::URL, $crawler->filter('[data-competitor-list-link]')->attr('href'), $page);
        }

        // The other languages' routes
        $browser->request('GET', '/de/teilnehmer/' . self::CUP);
        self::assertResponseIsSuccessful();
        $browser->request('GET', '/soutezici/' . self::CUP);
        self::assertResponseIsSuccessful();
    }

    private function turnOn(KernelBrowser $browser): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = true WHERE id = :id',
            ['id' => self::CUP],
        );
    }

    /**
     * @return list<string>
     */
    private function people(Crawler $crawler): array
    {
        return $crawler->filter('[data-competitor-list-row="p"] [data-competitor-list-name]')->each(static fn (Crawler $name): string => $name->text());
    }

    /**
     * @return list<string>
     */
    private function lineups(Crawler $crawler): array
    {
        return $crawler->filter('[data-competitor-list-row="g"] [data-competitor-list-name]')->each(static fn (Crawler $name): string => $name->text());
    }
}
