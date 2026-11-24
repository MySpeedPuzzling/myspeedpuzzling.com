<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionTagSheet;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The competition toolkit's print page of tags (B-qr-competitor-page.md §3.4, package B3): `?kind=people|lineups|
 * tables|entries` × `?layout=tags|tents` on competition_name_tags, offered once the competition has a QR mode or
 * competitor pages, or to an organiser who asks for it; each kind's QR is its frozen tag path (R §1.3); one statement per
 * kind; #CODE only for public profiles. Without any of that the page is the name tags page of before
 * (CompetitionNameTagsGoldenTest).
 */
final class CompetitionTagsPrintTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string PAGE = '/en/name-tags/' . self::EVENT;

    public function testWithoutAQrModeTheOrganiserGetsTheNameTagsOfBeforeUntilAskingForAKind(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-tag-controls]'));
        self::assertCount(9, $page->filter('.tag'));

        $page = $browser->request('GET', self::PAGE . '?kind=tables&layout=tents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-tag-controls]'));
    }

    public function testAnAdminSeesTheKindsOnlyWhenAskingForThem(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-tag-controls]'));

        $page = $browser->request('GET', self::PAGE . '?kind=tables');
        self::assertCount(1, $page->filter('[data-tag-controls]'));
        self::assertSame('tables', $page->filter('[data-tag-kind] option[selected]')->attr('value'));
        // A table's card stands on the table
        self::assertSame('tents', $page->filter('[data-tag-layout] option[selected]')->attr('value'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modes(): iterable
    {
        yield 'entrant (solo rounds too) prints people' => ['entrant', 'people'];
        yield 'table prints tables' => ['table', 'tables'];
        yield 'round entry prints a round\'s entries' => ['round_entry', 'entries'];
    }

    #[DataProvider('modes')]
    public function testTheDefaultKindFollowsTheQrMode(string $mode, string $kind): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode($mode);

        $page = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame($kind, $page->filter('[data-tag-kind] option[selected]')->attr('value'));
        self::assertSame($kind === 'tables' ? 'tents' : 'tags', $page->filter('[data-tag-layout] option[selected]')->attr('value'));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function kindsAndLayouts(): iterable
    {
        foreach (['tags', 'tents'] as $layout) {
            yield "people, {$layout}" => ['people', $layout, 9];
            // Every pair of the pairs round
            yield "lineups, {$layout}" => ['lineups', $layout, 4];
            // Tables 1-5 (Group A's highest)
            yield "tables, {$layout}" => ['tables', $layout, 5];
            // Group A, the first round by schedule
            yield "entries, {$layout}" => ['entries', $layout, 6];
        }
    }

    #[DataProvider('kindsAndLayouts')]
    public function testEveryKindPrintsInEveryLayout(string $kind, string $layout, int $count): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        $page = $browser->request('GET', self::PAGE . "?kind={$kind}&layout={$layout}");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertCount($count, $page->filter('[data-tag]'));

        if ($layout === 'tents') {
            self::assertCount($count, $page->filter('.tent'));
            // Both halves of a folded card, the upper one upside down - the same QR on both
            self::assertCount($count * 2, $page->filter('.tent-face svg'));
            self::assertCount($count, $page->filter('.tent-face.upper'));
            self::assertStringContainsString('size: A4 landscape', (string) $browser->getResponse()->getContent());
        } else {
            self::assertCount($count, $page->filter('.sheet .tag .tag-qr svg'));
            self::assertStringNotContainsString('size: A4 landscape', (string) $browser->getResponse()->getContent());
        }
    }

    public function testEachKindsQrIsItsFrozenTagPath(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');
        $qr = self::getContainer()->get(NameTagQrCode::class);

        $host = '~^https?://[^/]+';
        self::assertMatchesRegularExpression($host . '/live/' . self::EVENT . '/t/12$~', $qr->tableUrl(self::EVENT, 12));
        self::assertMatchesRegularExpression($host . '/live/' . self::EVENT . '/e/' . OfficialResultsFixture::TEAM_SHARKS . '$~', $qr->entryUrl(self::EVENT, OfficialResultsFixture::TEAM_SHARKS));
        $sharks = CompetitionLineupId::of(self::EVENT, [OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_BEN]);
        self::assertMatchesRegularExpression($host . '/live/' . self::EVENT . '/g/' . $sharks . '$~', $qr->lineupUrl(self::EVENT, $sharks));

        // The code drawn is the URL's (cached per URL)
        $page = $browser->request('GET', self::PAGE . '?kind=tables&layout=tags');
        self::assertStringContainsString($qr->svg($qr->tableUrl(self::EVENT, 1)), (string) $browser->getResponse()->getContent());

        // Tables: table 1 first
        self::assertSame(self::path($qr->tableUrl(self::EVENT, 1)), self::path((string) $page->filter('.tag-qr')->eq(0)->attr('data-qr-url')));

        // A solo round's entry: the person's CompetitionParticipantRound (Anna at table 1 of Group A)
        $page = $browser->request('GET', self::PAGE . '?kind=entries');
        self::assertSame(self::path($qr->entryUrl(self::EVENT, OfficialResultsFixture::ENTRY_A_ANNA)), self::path((string) $page->filter('.tag-qr')->eq(0)->attr('data-qr-url')));

        // A pair round's entry: the pair (CompetitionTeam), at table 1 of the pairs round
        $page = $browser->request('GET', self::PAGE . '?kind=entries&round=' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame(self::path($qr->entryUrl(self::EVENT, OfficialResultsFixture::TEAM_SHARKS)), self::path((string) $page->filter('.tag-qr')->eq(0)->attr('data-qr-url')));
        self::assertSame('Puzzle Sharks', trim($page->filter('.tag-name')->eq(0)->text()));

        // A line-up: by its exact people - the URL the line-up tag route resolves (Puzzle Sharks at table 1)
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&sort=table');
        self::assertSame(self::path($qr->lineupUrl(self::EVENT, $sharks)), self::path((string) $page->filter('.tag-qr')->eq(0)->attr('data-qr-url')));

        // People keep the printed person path with a language
        $page = $browser->request('GET', self::PAGE . '?kind=people');
        self::assertSame(self::path($qr->url(self::EVENT, OfficialResultsFixture::PARTICIPANT_ANNA, 'en')), self::path((string) $page->filter('.tag-qr')->eq(0)->attr('data-qr-url')));
    }

    public function testTheCodeIsPrintedOnlyForPublicProfiles(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        foreach (['?kind=lineups', '?kind=entries&round=' . OfficialResultsFixture::ROUND_PAIRS, '?kind=entries&round=' . OfficialResultsFixture::ROUND_GROUP_B, '?kind=people'] as $query) {
            foreach (['tags', 'tents'] as $layout) {
                $page = $browser->request('GET', self::PAGE . $query . '&layout=' . $layout);
                $html = (string) $browser->getResponse()->getContent();

                // Hugo's profile is public, Gina's (PLAYER_PRIVATE) is not
                self::assertStringContainsString('Gina Quick', $html, $query);
                self::assertStringContainsString('#PLAYER1', $html, $query);
                self::assertStringNotContainsString('#PLAYER2', $html, $query);
                self::assertStringNotContainsStringIgnoringCase('player2', $html, $query);
                self::assertGreaterThan(0, $page->filter('[data-tag]')->count());
            }
        }
    }

    public function testPairsShowTheirPeopleAndAnUnnamedPairItsNamesLarge(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        $page = $browser->request('GET', self::PAGE . '?kind=lineups');

        // By name: an unnamed pair by its people
        $labels = $page->filter('[data-tag]')->each(static fn (Crawler $tag): string => $tag->filter('.tag-name')->count() > 0
            ? trim($tag->filter('.tag-name')->text())
            : implode(' & ', $tag->filter('.member-name')->each(static fn (Crawler $name): string => trim($name->text()))));
        self::assertSame(['Corner Pieces', 'Edge Hunters', 'Eva Noshow & Filip Pending', 'Puzzle Sharks'], $labels);
        self::assertCount(1, $page->filter('.tag-members.unnamed'));
        self::assertSame(['Anna Fast', 'Ben Steady'], $page->filter('[data-tag]')->eq(3)->filter('.member-name')->each(static fn (Crawler $name): string => trim($name->text())));
        self::assertSame('1', $page->filter('[data-tag]')->eq(3)->filter('.tag-table strong')->text());
        self::assertCount(1, $page->filter('[data-first-round-note]'));
        self::assertCount(0, $page->filter('[data-lineup-changes]'));
    }

    public function testAPairChangedInALaterRoundIsNamedOnThePage(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');
        $database = self::getContainer()->get(Connection::class);
        // Pairs Final: Anna plays with Cara - the Puzzle Sharks' and Corner Pieces' tags do not show that round
        $team = Uuid::uuid7()->toString();
        $database->insert('competition_team', ['id' => $team, 'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'name' => null, 'table_number' => 1]);

        foreach ([OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_CARA] as $participant) {
            $database->insert('competition_participant_round', ['id' => Uuid::uuid7()->toString(), 'participant_id' => $participant, 'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'team_id' => $team]);
        }

        $page = $browser->request('GET', self::PAGE . '?kind=lineups');

        self::assertCount(5, $page->filter('[data-tag]'));
        self::assertSame(
            ['Puzzle Sharks → in Pairs Final: Anna Fast & Cara Tied', 'Corner Pieces → in Pairs Final: Anna Fast & Cara Tied'],
            ($page->filter('[data-lineup-changes] li')->each(static fn (Crawler $item): string => trim($item->text()))),
        );

        // The final's line-up alone, with the final's table
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&round=' . OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertCount(1, $page->filter('[data-tag]'));
        self::assertSame(['Anna Fast', 'Cara Tied'], $page->filter('.member-name')->each(static fn (Crawler $name): string => trim($name->text())));
    }

    public function testTablesNobodySitsAtStillGetTheirCard(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('table');

        // 1 to the highest number in use, gaps included
        $page = $browser->request('GET', self::PAGE . '?layout=tags');
        self::assertSame(['1', '2', '3', '4', '5'], $page->filter('.tag-table strong')->each(static fn (Crawler $number): string => $number->text()));
        self::assertStringContainsString('Tables in use: 1, 2, 3, 4, 5', $page->filter('[data-tables-used]')->text());

        // A range of the organiser's
        $page = $browser->request('GET', self::PAGE . '?layout=tags&from=4&to=8');
        self::assertSame(['4', '5', '6', '7', '8'], $page->filter('.tag-table strong')->each(static fn (Crawler $number): string => $number->text()));

        // A round nobody is seated in yet: no tables until a range is given
        $page = $browser->request('GET', self::PAGE . '?round=' . OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertCount(0, $page->filter('[data-tag]'));
        self::assertStringContainsString('No table numbers are assigned yet', $page->filter('[data-tables-used]')->text());
        $page = $browser->request('GET', self::PAGE . '?round=' . OfficialResultsFixture::ROUND_PAIRS_FINAL . '&to=3');
        self::assertCount(3, $page->filter('[data-tag]'));

        // Never more than a few hundred cards at once
        $page = $browser->request('GET', self::PAGE . '?layout=tags&to=9999');
        self::assertCount(CompetitionTagSheet::MAX_TABLES, $page->filter('[data-tag]'));

        // No name on a table's card: whoever sits there changes
        $page = $browser->request('GET', self::PAGE);
        self::assertStringNotContainsString('Anna Fast', (string) $browser->getResponse()->getContent());
        self::assertCount(0, $page->filter('.tent-scan'));
    }

    public function testATableCardInvitesToScanOnlyWithCompetitorPages(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('table');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => self::EVENT]);

        $page = $browser->request('GET', self::PAGE);

        self::assertCount(10, $page->filter('.tent-scan'));
    }

    public function testEntriesOfAPairRoundAreItsPairsByTable(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('round_entry');

        $page = $browser->request('GET', self::PAGE . '?round=' . OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame(['1', '2', '3'], $page->filter('.tag-table strong')->each(static fn (Crawler $number): string => $number->text()));
        self::assertCount(4, $page->filter('[data-tag]'));
        // The round is always one round: no "everybody" choice
        self::assertCount(0, $page->filter('select[name="round"] option[value=""]'));
        self::assertSame(OfficialResultsFixture::ROUND_PAIRS, $page->filter('select[name="round"] option[selected]')->attr('value'));
    }

    public function testCountryFlagsAreOffUntilTheOrganiserAsks(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        foreach (['tags', 'tents'] as $layout) {
            $page = $browser->request('GET', self::PAGE . '?kind=people&layout=' . $layout);

            self::assertResponseIsSuccessful();
            self::assertCount(1, $page->filter('[data-tag-flags]'));
            self::assertNull($page->filter('[data-tag-flags]')->attr('checked'));
            self::assertCount(0, $page->filter('[data-name-flag]'));
            self::assertCount(0, $page->filter('body.with-flags'));
            // The small flag under the name, as before
            self::assertCount(9 * ($layout === 'tents' ? 2 : 1), $page->filter('.tag-who img'));
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function flagKindsAndLayouts(): iterable
    {
        foreach (['tags' => 1, 'tents' => 2] as $layout => $faces) {
            yield "people, {$layout}" => ['people', $layout, 9 * $faces];
            yield "entries of a solo round, {$layout}" => ['entries', $layout, 6 * $faces];
        }
    }

    #[DataProvider('flagKindsAndLayouts')]
    public function testAPersonsFlagIsPrintedLargeBeforeTheirName(string $kind, string $layout, int $flags): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        $page = $browser->request('GET', self::PAGE . "?kind={$kind}&layout={$layout}&flags=1");

        self::assertResponseIsSuccessful();
        self::assertNotNull($page->filter('[data-tag-flags]')->attr('checked'));
        self::assertCount(1, $page->filter('body.with-flags'));
        self::assertCount($flags, $page->filter($layout === 'tents' ? '.tent-name [data-name-flag]' : '.tag-name [data-name-flag]'));
        // Not twice: the line under the name keeps only the #CODE
        self::assertCount(0, $page->filter('.tag-who img'));
        self::assertStringContainsString('/build/images/cz', (string) $page->filter('[data-name-flag]')->eq(0)->attr('src'));
    }

    public function testPairsAndTeamsCarryEachPersonsFlagAndNoneWhenUnknown(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');
        // Ben is not linked to a profile: no country at all
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition_participant SET country = NULL WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_BEN]);

        foreach (['?kind=lineups', '?kind=entries&round=' . OfficialResultsFixture::ROUND_PAIRS] as $query) {
            foreach (['tags', 'tents'] as $layout) {
                $page = $browser->request('GET', self::PAGE . $query . '&layout=' . $layout . '&flags=1');
                self::assertResponseIsSuccessful();

                $sharks = $page->filter('[data-tag]')->reduce(static fn (Crawler $tag): bool => str_contains($tag->text(), 'Puzzle Sharks'))->first();
                $faces = $layout === 'tents' ? 2 : 1;
                // Anna's flag next to her name, nothing next to Ben's
                self::assertSame(array_merge(...array_fill(0, $faces, ['CZ'])), $sharks->filter('.tag-member img')->each(static fn (Crawler $flag): string => (string) $flag->attr('alt')), $query);
                self::assertCount(2 * $faces, $sharks->filter('.tag-member'), $query);
                // A pair has no flag of its own
                self::assertCount(0, $page->filter('[data-name-flag]'), $query);
            }
        }
    }

    public function testAMissingCountryIsTakenFromAPublicProfileOnlyAndOnlyWithFlags(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');
        $database = self::getContainer()->get(Connection::class);
        // Anna (linked to a public profile) and Gina (a private one) without a country on the participant list
        $database->executeStatement('UPDATE competition_participant SET country = NULL WHERE id IN (:anna, :gina)', ['anna' => OfficialResultsFixture::PARTICIPANT_ANNA, 'gina' => OfficialResultsFixture::PARTICIPANT_GINA]);
        $database->executeStatement('UPDATE player SET country = :country, is_private = false WHERE id = :id', ['country' => 'pt', 'id' => PlayerFixture::PLAYER_ADMIN]);
        $database->executeStatement('UPDATE player SET country = :country, is_private = true WHERE id = :id', ['country' => 'fr', 'id' => PlayerFixture::PLAYER_PRIVATE]);

        foreach (['?kind=people', '?kind=lineups', '?kind=entries&round=' . OfficialResultsFixture::ROUND_GROUP_B, '?kind=entries'] as $query) {
            foreach (['tags', 'tents'] as $layout) {
                $browser->request('GET', self::PAGE . $query . '&layout=' . $layout);
                $html = (string) $browser->getResponse()->getContent();
                // Without the option the tags are the ones of before: the participant list's country only
                self::assertStringNotContainsString('alt="PT"', $html, $query);
                self::assertStringNotContainsString('alt="FR"', $html, $query);

                $browser->request('GET', self::PAGE . $query . '&layout=' . $layout . '&flags=1');
                $html = (string) $browser->getResponse()->getContent();
                // A private profile's country is never printed - the #CODE rule
                self::assertStringNotContainsString('alt="FR"', $html, $query);
                self::assertStringNotContainsStringIgnoringCase('/build/images/fr', $html, $query);

                if (str_contains($html, 'Anna Fast')) {
                    self::assertStringContainsString('alt="PT"', $html, $query);
                }
            }
        }

        $browser->request('GET', self::PAGE . '?kind=people&flags=1');
        self::assertStringContainsString('alt="PT"', (string) $browser->getResponse()->getContent());
    }

    public function testTablesOfferNoFlagsButKeepTheChoice(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');

        $page = $browser->request('GET', self::PAGE . '?kind=tables&flags=1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-tag-flags]'));
        self::assertCount(0, $page->filter('body.with-flags'));
        self::assertCount(1, $page->filter('input[type="hidden"][name="flags"][value="1"]'));

        $page = $browser->request('GET', self::PAGE . '?kind=tables');
        self::assertCount(0, $page->filter('input[name="flags"]'));
    }

    /**
     * One statement per kind: the tags of 4 or 200 entries cost the same.
     *
     * @return iterable<string, array{string}>
     */
    public static function kinds(): iterable
    {
        yield 'people' => ['people'];
        yield 'lineups' => ['lineups'];
        yield 'tables' => ['tables'];
        yield 'entries' => ['entries'];
    }

    #[DataProvider('kinds')]
    public function testEachKindReadsItsTagsInOneStatement(string $kind): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setQrMode('entrant');
        $browser->request('GET', self::PAGE . '?kind=' . $kind);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE . '?kind=' . $kind);
        self::assertResponseIsSuccessful();

        $tagStatements = array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'competition_participant_round') || str_contains($sql, 'competition_team'),
        ));

        // The event and its rounds overview read entries too; the kind's own read is the one other statement
        self::assertCount(3, $tagStatements, implode("\n---\n", $tagStatements));
    }

    public function testTableNamesArePrintedWhereTheRoundHasThem(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setTableLabels(OfficialResultsFixture::ROUND_PAIRS, ['A1 L', 'A1 R', 'A2 L', 'A2 R']);

        // A round's entries: Puzzle Sharks sits at table 1 = A1 L
        $page = $browser->request('GET', self::PAGE . '?kind=entries&round=' . OfficialResultsFixture::ROUND_PAIRS . '&sort=table');
        self::assertResponseIsSuccessful();
        self::assertSame('A1 L', trim($page->filter('.tag-table strong')->first()->text()));

        // Table cards print the first labelled round's names; the QR stays the number's frozen path
        $page = $browser->request('GET', self::PAGE . '?kind=tables');
        self::assertResponseIsSuccessful();
        self::assertSame('A1 L', trim($page->filter('.tent-table.only strong')->first()->text()));
        self::assertSame('/live/' . self::EVENT . '/t/1', self::path((string) $page->filter('[data-qr-url]')->first()->attr('data-qr-url')));
        self::assertCount(1, $page->filter('[data-table-names-hint]'));

        // A round without names prints numbers as before
        $browser->request('GET', self::PAGE . '?kind=entries&round=' . OfficialResultsFixture::ROUND_GROUP_A . '&sort=table');
        self::assertDoesNotMatchRegularExpression('/A1 L/', (string) $browser->getResponse()->getContent());
    }

    public function testPlatesCarryABoxPerChallengeOnlyWhenAsked(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);

        // Tents offer the choice; nothing printed differs until it is made
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&layout=tents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-tag-boxes]'));
        self::assertCount(0, $page->filter('[data-time-boxes]'));
        // The sheet of tags never offers it
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&layout=tags');
        self::assertCount(0, $page->filter('[data-tag-boxes]'));

        // The pairs rounds of the event: Pairs, then Pairs Final - a box each, on the face toward the judge only
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&layout=tents&boxes=duo');
        self::assertResponseIsSuccessful();
        $tents = $page->filter('.tent');
        self::assertGreaterThan(0, $tents->count());
        self::assertCount($tents->count(), $page->filter('.tent-face.lower [data-time-boxes]'));
        self::assertCount(0, $page->filter('.tent-face.upper [data-time-boxes]'));
        self::assertSame(['Pairs', 'Pairs Final'], $tents->first()->filter('.time-box-label')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertCount(2, $tents->first()->filter('.time-box-field.missing'));
        // The printed round is the championship's first: its line-ups
        self::assertSame('duo', $page->filter('[data-tag-boxes] option[selected]')->attr('value'));
        self::assertCount(1, $page->filter('[data-plates-hint]'));

        // Without the "missing pieces" field
        $page = $browser->request('GET', self::PAGE . '?kind=lineups&layout=tents&boxes=duo&missing=0');
        self::assertCount(0, $page->filter('.time-box-field.missing'));
        self::assertCount(2, $page->filter('.tent')->first()->filter('.time-box'));
    }

    /**
     * @param list<string> $labels
     */
    private function setTableLabels(string $roundId, array $labels): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition_round SET table_labels = :labels WHERE id = :id', ['labels' => json_encode($labels, JSON_THROW_ON_ERROR), 'id' => $roundId]);
    }

    private static function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function setQrMode(string $mode): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET qr_tag_mode = :mode WHERE id = :id', ['mode' => $mode, 'id' => self::EVENT]);
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
