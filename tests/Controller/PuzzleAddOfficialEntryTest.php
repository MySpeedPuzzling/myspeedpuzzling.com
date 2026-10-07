<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Add to my profile" of a round's published official results (docs/features/competitions-management/official-results.md):
 * the add-time form reads `?official_entry=` back on the server and fills itself in - only when the round page offers
 * exactly that to this player; anything else is ignored silently. Results Cup: Group A published, PUZZLE_1000_05, the
 * round day 10 days ago; Anna (1:00:00) is PLAYER_ADMIN, Pairs (PUZZLE_2000) gets published where needed.
 */
final class PuzzleAddOfficialEntryTest extends WebTestCase
{
    private const string ADMIN_USER_ID = 'auth0|admin003';

    public function testTheViewersOwnFinishedEntryFillsTheFormIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_1000_05, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA));

        $this->assertResponseIsSuccessful();
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $this->value($crawler, 'puzzle'));
        self::assertSame(['1', '0', '0'], [$this->value($crawler, 'timeHours'), $this->value($crawler, 'timeMinutes'), $this->value($crawler, 'timeSeconds')]);
        self::assertSame($this->roundDay()->format('d.m.Y'), $this->value($crawler, 'finishedAt'));
        self::assertSame(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $this->value($crawler, 'competition'));
        // Solo: nobody to puzzle with
        self::assertCount(0, $crawler->filter('input[name="group_players[]"]'));
        $this->assertSelectorTextContains('[data-official-entry-notice]', 'Filled in from the official results of Group A at Results Cup');
        $this->assertSelectorNotExists('[data-official-entry-check-group]');
    }

    public function testTheRoundsPuzzleIsFilledInWithoutOneInTheUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', $this->url(null, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA));

        $this->assertResponseIsSuccessful();
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $this->value($crawler, 'puzzle'));
        self::assertSame('1', $this->value($crawler, 'timeHours'));
    }

    public function testAnEntryNobodyIsLinkedToFillsTheFormInForAPlayerLinkedToNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_1000_05, 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN));

        self::assertSame(['1', '10', '0'], [$this->value($crawler, 'timeHours'), $this->value($crawler, 'timeMinutes'), $this->value($crawler, 'timeSeconds')]);
        $this->assertSelectorExists('[data-official-entry-notice]');
    }

    /**
     * @return iterable<string, array{string, string, null|string}>
     */
    public static function provideEntriesNotOffered(): iterable
    {
        $anna = 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA;

        yield 'somebody else\'s entry' => [PlayerFixture::PLAYER_REGULAR, $anna, null];
        yield 'an entry nobody is linked to, for a player the organiser linked to their own' => [PlayerFixture::PLAYER_ADMIN, 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN, null];
        yield 'unfinished' => [PlayerFixture::PLAYER_WITH_FAVORITES, 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN, null];
        yield 'did not start' => [PlayerFixture::PLAYER_WITH_FAVORITES, 'participant_round:' . OfficialResultsFixture::ENTRY_A_EVA, null];
        yield 'no result yet' => [PlayerFixture::PLAYER_WITH_FAVORITES, 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, null];
        yield 'round not published' => [PlayerFixture::PLAYER_REGULAR, 'participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO, null];
        yield 'another puzzle in the URL' => [PlayerFixture::PLAYER_ADMIN, $anna, PuzzleFixture::PUZZLE_500_03];
        yield 'a team id as a person\'s entry' => [PlayerFixture::PLAYER_ADMIN, 'participant_round:' . OfficialResultsFixture::TEAM_SHARKS, null];
        yield 'not an entry' => [PlayerFixture::PLAYER_ADMIN, 'participant_round:not-a-uuid', null];
        yield 'unknown kind' => [PlayerFixture::PLAYER_ADMIN, 'player:' . OfficialResultsFixture::ENTRY_A_ANNA, null];
    }

    #[DataProvider('provideEntriesNotOffered')]
    public function testAnythingTheRoundPageDoesNotOfferIsIgnored(string $viewer, string $officialEntry, null|string $puzzleId): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $viewer);

        $crawler = $browser->request('GET', $this->url($puzzleId, $officialEntry));

        $this->assertResponseIsSuccessful();
        $this->assertNotFilledIn($crawler);
        // The competition of the link is still pre-selected, as by any ?competition=
        self::assertSame(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $this->value($crawler, 'competition'));
    }

    public function testAnEntryOfAnotherCompetitionIsIgnored(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', sprintf(
            '/en/puzzle-add?competition=%s&official_entry=participant_round:%s',
            CompetitionFixture::COMPETITION_WJPC_2024,
            OfficialResultsFixture::ENTRY_A_ANNA,
        ));

        $this->assertNotFilledIn($crawler);
    }

    public function testWithoutTheCompetitionOrInRelaxModeNothingIsFilledIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $urls = [
            '/en/puzzle-add?official_entry=participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA,
            '/en/puzzle-add?mode=relax&competition=' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '&official_entry=participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA,
        ];

        foreach ($urls as $url) {
            $this->assertNotFilledIn($browser->request('GET', $url));
        }
    }

    public function testAPlayerWithATimeInTheRoundIsNotOfferedAnotherOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->addTime(self::ADMIN_USER_ID, PuzzleFixture::PUZZLE_1000_05, '01:00:30');

        $this->assertNotFilledIn($browser->request('GET', $this->url(PuzzleFixture::PUZZLE_1000_05, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA)));
    }

    public function testAPuzzleNotRevealedYetIsNeverFilledIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_image_until = NOW() + INTERVAL '1 day' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_1000_05],
        );

        $this->assertNotFilledIn($browser->request('GET', $this->url(null, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA)));
    }

    public function testAPuzzleTheCompetitionStillKeepsSecretIsNeverFilledIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '1 day' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_1000_05],
        );

        $this->assertNotFilledIn($browser->request('GET', $this->url(null, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA)));

        // Nor does the round page offer it
        $browser->request('GET', '/en/events/results-cup/results/group-a');
        $this->assertSelectorExists('[data-official-results]');
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
    }

    public function testAPairWithLinkedMembersBringsThemAsPlayersAndTheOthersAsGuests(): void
    {
        $browser = self::createClient();
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);

        // Anna's "Puzzle Sharks" with Ben, whom nobody is linked to
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_2000, 'team:' . OfficialResultsFixture::TEAM_SHARKS));

        self::assertSame(PuzzleFixture::PUZZLE_2000, $this->value($crawler, 'puzzle'));
        self::assertSame(['1', '30', '0'], [$this->value($crawler, 'timeHours'), $this->value($crawler, 'timeMinutes'), $this->value($crawler, 'timeSeconds')]);
        self::assertSame(['Ben Steady'], $this->groupPlayers($crawler));
        self::assertSame('Puzzle Sharks', $crawler->filter('input[name="team_name"]')->attr('value'));

        // Hugo's "Edge Hunters" with Gina - a linked (private) player, by her code
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_2000, 'team:' . OfficialResultsFixture::TEAM_EDGES));

        self::assertSame(['#PLAYER2'], $this->groupPlayers($crawler));
        self::assertSame(['1', '40', '0'], [$this->value($crawler, 'timeHours'), $this->value($crawler, 'timeMinutes'), $this->value($crawler, 'timeSeconds')]);
    }

    public function testThePairsNameIsNotOfferedWhenTheseExactPeopleAreANamedPairAlready(): void
    {
        $browser = self::createClient();
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        // Anna and "Ben Steady" puzzled as "Old Sharks" before - outside any round
        $this->addTime(self::ADMIN_USER_ID, PuzzleFixture::PUZZLE_500_03, '00:40:00', ['Ben Steady'], 'Old Sharks');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_2000, 'team:' . OfficialResultsFixture::TEAM_SHARKS));

        self::assertSame(['Ben Steady'], $this->groupPlayers($crawler));
        self::assertSame('', (string) $crawler->filter('input[name="team_name"]')->attr('value'));
    }

    public function testAPairNobodyIsLinkedToLeavesTheViewersOwnNameOut(): void
    {
        $browser = self::createClient();
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        // The organiser typed names only - one of them is PLAYER_WITH_FAVORITES's own
        $teamId = $this->addUnlinkedPair('Minnesota Mates', ['Kim Lee', 'Michael  johnson'], 5000);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_2000, 'team:' . $teamId));

        self::assertSame(['Kim Lee'], $this->groupPlayers($crawler));
        self::assertSame('Minnesota Mates', $crawler->filter('input[name="team_name"]')->attr('value'));
        $this->assertSelectorNotExists('[data-official-entry-check-group]');
    }

    public function testAPairNobodyIsLinkedToWithoutTheViewersNameAsksThemToCheck(): void
    {
        $browser = self::createClient();
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        $teamId = $this->addUnlinkedPair(null, ['Kim Lee', 'Mike J.'], 5000);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_2000, 'team:' . $teamId));

        self::assertSame(['Kim Lee', 'Mike J.'], $this->groupPlayers($crawler));
        self::assertSame('', (string) $crawler->filter('input[name="team_name"]')->attr('value'));
        $this->assertSelectorExists('[data-official-entry-check-group]');
    }

    public function testThePrefilledFormSavesAnOrdinaryTimeAndTheRoundPageSaysItIsOnTheProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', $this->url(PuzzleFixture::PUZZLE_1000_05, 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA));
        $browser->submit($crawler->filter('form[name="puzzle_add_form"]')->form());

        $this->assertResponseRedirects();
        /** @var false|array{seconds_to_solve: int, competition_round_id: null|string, finished_at: string} $time */
        $time = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT seconds_to_solve, competition_round_id, finished_at FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => PlayerFixture::PLAYER_ADMIN, 'puzzleId' => PuzzleFixture::PUZZLE_1000_05],
        );
        self::assertIsArray($time);
        self::assertSame(3600, $time['seconds_to_solve']);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $time['competition_round_id']);
        self::assertStringStartsWith($this->roundDay()->format('Y-m-d'), $time['finished_at']);

        $browser->request('GET', '/en/events/results-cup/results/group-a');
        $this->assertSelectorExists('[data-official-on-profile]');
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
    }

    private function url(null|string $puzzleId, string $officialEntry): string
    {
        return sprintf(
            '/en/puzzle-add%s?competition=%s&official_entry=%s',
            $puzzleId !== null ? '/' . $puzzleId : '',
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            $officialEntry,
        );
    }

    private function value(Crawler $crawler, string $field): string
    {
        $input = $crawler->filter(sprintf('[name="puzzle_add_form[%s]"]', $field));
        self::assertCount(1, $input, $field);

        return (string) $input->attr('value');
    }

    /**
     * @return list<string>
     */
    private function groupPlayers(Crawler $crawler): array
    {
        return $crawler->filter('input[name="group_players[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    private function assertNotFilledIn(Crawler $crawler): void
    {
        self::assertSame(['0', '0', '0'], [$this->value($crawler, 'timeHours'), $this->value($crawler, 'timeMinutes'), $this->value($crawler, 'timeSeconds')]);
        self::assertCount(0, $crawler->filter('[data-official-entry-notice]'));
        self::assertCount(0, $crawler->filter('input[name="group_players[]"]'));
    }

    /**
     * The round day as the form shows it: the fixture rounds start 10 days ago, 09:00 UTC = 11:00 in Prague.
     */
    private function roundDay(): DateTimeImmutable
    {
        $startsAt = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT starts_at FROM competition_round WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_A],
        );
        self::assertIsString($startsAt);

        return new DateTimeImmutable($startsAt, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('Europe/Prague'));
    }

    private function publish(string $roundId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW(), results_first_published_at = NOW() WHERE id = :id',
            ['id' => $roundId],
        );
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function addTime(string $userId, string $puzzleId, string $time, array $groupPlayers = [], null|string $teamName = null): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $puzzleId === PuzzleFixture::PUZZLE_1000_05 ? OfficialResultsFixture::COMPETITION_RESULTS_CUP : null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
            teamName: $teamName,
        ));
    }

    /**
     * A pair of people the organiser typed by name only, nobody linked - the Minnesota case.
     *
     * @param list<string> $names
     */
    private function addUnlinkedPair(null|string $name, array $names, int $seconds): string
    {
        $database = self::getContainer()->get(Connection::class);
        $teamId = Uuid::uuid7()->toString();
        $database->executeStatement(
            'INSERT INTO competition_team (id, round_id, name, result_seconds, result_did_not_start) VALUES (:id, :roundId, :name, :seconds, false)',
            ['id' => $teamId, 'roundId' => OfficialResultsFixture::ROUND_PAIRS, 'name' => $name, 'seconds' => $seconds],
        );

        foreach ($names as $memberName) {
            $participantId = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, :name, 'us', :competitionId, 'imported')",
                ['id' => $participantId, 'name' => $memberName, 'competitionId' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
            );
            $database->executeStatement(
                'INSERT INTO competition_participant_round (id, participant_id, round_id, team_id, result_did_not_start) VALUES (:id, :participantId, :roundId, :teamId, false)',
                ['id' => Uuid::uuid7()->toString(), 'participantId' => $participantId, 'roundId' => OfficialResultsFixture::ROUND_PAIRS, 'teamId' => $teamId],
            );
        }

        return $teamId;
    }
}
