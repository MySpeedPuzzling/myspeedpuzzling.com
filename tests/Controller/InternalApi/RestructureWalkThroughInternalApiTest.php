<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FollowCompetition;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The restructuring of an organiser who put every event into one series, done through the internal API alone
 * (docs/features/organizations/README.md "Restructuring tools", docs/features/internal-api.md "Organizations, series
 * and drafts") - the exact sequence it is used for on production, with made-up names:
 *
 * 1. the series becomes an organization that takes over its address; the series gets a new name and address, its
 *    followers follow the organization, its old addresses redirect;
 * 2. two new in-person series under the organization, approved at once;
 * 3. editions of them, with a round;
 * 4. a round without a puzzle gets the puzzle of 7 results linked to the edition only - they join the round - and the
 *    round moves to a new edition, the results with it;
 * 5. the other rounds move one by one into new editions; the old edition keeps its last round and is renamed;
 * 6. the other two editions move to the new series and get one-day dates, names and addresses.
 */
final class RestructureWalkThroughInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string OLD_SERIES_SLUG = 'quarry-hollow-puzzlers';

    /** The puzzles of the 4 rounds with results */
    private const array ROUND_PUZZLES = [
        PuzzleFixture::PUZZLE_500_03,
        PuzzleFixture::PUZZLE_500_04,
        PuzzleFixture::PUZZLE_500_05,
        PuzzleFixture::PUZZLE_1000_01,
    ];

    /** The puzzle of the 7 results linked to the edition but to no round */
    private const string LOOSE_PUZZLE = PuzzleFixture::PUZZLE_1000_02;

    private const array PLAYERS = [
        PlayerFixture::PLAYER_REGULAR_USER_ID,
        PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID,
        PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
        PlayerFixture::PLAYER_PRIVATE_USER_ID,
    ];

    public function testTheWholeRestructureThroughTheInternalApi(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        // --- The organiser's data as it is: one online series holding everything ---------------------------------

        $oldSeries = self::callInternalApi($browser, 'POST', '/internal-api/series', [
            'name' => 'Quarry Hollow Puzzlers',
            'slug' => self::OLD_SERIES_SLUG,
            'isOnline' => true,
            'locationCountryCode' => 'us',
            'link' => 'https://quarry-hollow.example',
            'description' => 'Monthly virtual contests and casual nights.',
            'maintainerIds' => [PlayerFixture::PLAYER_WITH_FAVORITES],
            'approve' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('approved', $oldSeries['status']);
        $oldSeriesId = self::string($oldSeries['seriesId']);

        // (a) an online edition without dates, 6 solo rounds
        $editionA = $this->createEdition($browser, $oldSeriesId, ['name' => 'Quarry Hollow Virtual Season', 'slug' => 'virtual-season', 'dateFrom' => '2026-01-10', 'dateTo' => '2026-01-10']);
        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . $editionA, ['dateFrom' => null, 'dateTo' => null]);
        self::assertResponseIsSuccessful();

        $roundsWithResults = [];

        foreach (self::ROUND_PUZZLES as $index => $puzzleId) {
            $roundsWithResults[] = $this->createRound($browser, $editionA, [
                'name' => sprintf('Virtual Contest %d', $index + 1),
                'startsAt' => sprintf('2026-0%d-15T19:00:00-04:00', $index + 3),
                'minutesLimit' => 90,
                'puzzleIds' => [$puzzleId],
            ]);
        }

        $roundWithoutPuzzle = $this->createRound($browser, $editionA, ['name' => 'Virtual Contest 5', 'startsAt' => '2026-07-15T19:00:00-04:00', 'minutesLimit' => 90]);
        $upcomingRound = $this->createRound($browser, $editionA, ['name' => 'Virtual Contest 6', 'startsAt' => '2027-01-20T19:00:00-05:00', 'minutesLimit' => 90]);

        // A result in each of the 4 rounds - linked to its round through the round's puzzle
        foreach (self::ROUND_PUZZLES as $index => $puzzleId) {
            $this->addTime(self::PLAYERS[$index], $puzzleId, $editionA, sprintf('00:%d:00', 40 + $index));
        }

        // 7 results linked to the edition, but to no round: their puzzle is in none of its rounds
        foreach (range(0, 6) as $index) {
            $this->addTime(self::PLAYERS[$index % 4], self::LOOSE_PUZZLE, $editionA, sprintf('01:%02d:%02d', 10 + $index, $index));
        }

        $a = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . $editionA);
        self::assertSame(6, $a['roundsCount']);
        self::assertSame(11, $a['resultsCount']);
        self::assertSame(7, $a['resultsWithoutRoundCount']);

        // (b) wrongly online, 14 months long, no rounds
        $editionB = $this->createEdition($browser, $oldSeriesId, ['name' => 'Quarry Hollow Brewery Nights', 'slug' => 'brewery-nights', 'dateFrom' => '2026-02-02', 'dateTo' => '2027-04-02']);

        // (c) in person, one participant
        $editionC = $this->createEdition($browser, $oldSeriesId, ['name' => 'Quarry Hollow Pub Puzzle', 'slug' => 'pub-puzzle', 'dateFrom' => '2026-11-24', 'dateTo' => '2026-11-24']);
        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . $editionC, ['isOnline' => false, 'location' => 'Millbrook Taproom', 'locationCountryCode' => 'us']);
        self::assertResponseIsSuccessful();
        $this->messageBus()->dispatch(new JoinCompetition($editionC, PlayerFixture::PLAYER_REGULAR));

        // Two followers of the series
        $this->messageBus()->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . $oldSeriesId));
        $this->messageBus()->dispatch(new FollowCompetition(PlayerFixture::PLAYER_WITH_STRIPE, 'series:' . $oldSeriesId));

        // --- 1. The series becomes an organization that takes over its address --------------------------------------

        $organization = self::callInternalApi($browser, 'POST', '/internal-api/series/' . $oldSeriesId . '/create-organization', [
            'name' => 'Quarry Hollow Jigsaw Association',
            'shortName' => 'QHJA',
            'slug' => self::OLD_SERIES_SLUG,
            'kind' => 'association',
            'countryCode' => 'us',
            'region' => 'Quarry Hollow',
            'newSeriesName' => 'Quarry Hollow Virtual Contest',
            'newSeriesSlug' => 'quarry-hollow-virtual-contest',
        ]);
        self::assertResponseStatusCodeSame(201);
        $organizationId = self::string($organization['organizationId']);
        self::assertSame(self::OLD_SERIES_SLUG, $organization['slug']);
        self::assertSame('approved', $organization['status']);
        self::assertFalse($organization['draft']);
        self::assertTrue($organization['publiclyVisible']);
        self::assertSame('association', $organization['kind']);
        self::assertSame('https://quarry-hollow.example', $organization['website']);
        self::assertSame('Monthly virtual contests and casual nights.', $organization['about']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $organization['addedByPlayerId']);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], array_column(self::list($organization['maintainers']), 'playerId'));
        $organizationSeries = self::list($organization['series']);
        self::assertCount(1, $organizationSeries);
        self::assertSame($oldSeriesId, $organizationSeries[0]['seriesId']);
        self::assertSame('quarry-hollow-virtual-contest', $organizationSeries[0]['slug']);
        self::assertSame('Quarry Hollow Virtual Contest', $organizationSeries[0]['name']);
        self::assertSame(3, $organizationSeries[0]['editionsCount']);

        // Its social links and the rest follow with a PATCH
        $patched = self::callInternalApi($browser, 'PATCH', '/internal-api/organizations/' . $organizationId, [
            'socialLinks' => ['https://www.instagram.com/quarryhollowpuzzles', 'https://discord.gg/quarryhollow'],
            'about' => 'The jigsaw puzzle association of Quarry Hollow.',
            'website' => 'https://qhja.example',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['https://www.instagram.com/quarryhollowpuzzles', 'https://discord.gg/quarryhollow'], $patched['socialLinks']);
        self::assertSame(self::OLD_SERIES_SLUG, $patched['slug']);

        // The followers follow the organization now
        self::assertSame(0, $database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE series_id = :id', ['id' => $oldSeriesId]));
        self::assertSame(2, $database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE organization_id = :id', ['id' => $organizationId]));

        // The old series address leads to the organization, an old edition address to the edition
        $browser->request('GET', '/en/series/' . self::OLD_SERIES_SLUG);
        self::assertResponseRedirects('/en/organizations/' . self::OLD_SERIES_SLUG, 301);
        $browser->request('GET', '/en/series/' . self::OLD_SERIES_SLUG . '/pub-puzzle');
        self::assertResponseRedirects('/en/series/quarry-hollow-virtual-contest/pub-puzzle', 301);

        // --- 2. Two in-person series under the organization - approved at once (the reviewer is an admin) ----------

        $breweryNights = $this->createSeries($browser, $organizationId, 'Copper Kettle Puzzle Night', 'Copper Kettle Brewing, Millbrook', 'Second Thursday of the month, 7:30 pm');
        $pubPuzzles = $this->createSeries($browser, $organizationId, 'Old Mill Pub Puzzle', 'Old Mill Taproom, Millbrook', 'Every other Sunday, 2 pm');

        // --- 3. An edition with a round -------------------------------------------------------------------------------

        $firstNight = self::callInternalApi($browser, 'POST', '/internal-api/series/' . $breweryNights . '/editions', [
            'name' => 'Copper Kettle Puzzle Night - November',
            'dateFrom' => '2026-11-02',
            'dateTo' => '2026-11-02',
            'registrationLink' => 'https://quarry-hollow.example/register/november',
            'resultsLink' => 'https://quarry-hollow.example/results/november',
            'link' => 'https://quarry-hollow.example/nights',
            'description' => 'A casual night.',
            'eligibility' => '18+',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('copper-kettle-puzzle-night-november', $firstNight['slug']);
        self::assertSame('approved', $firstNight['status']);
        self::assertTrue($firstNight['publiclyVisible']);
        self::assertSame('18+', $firstNight['eligibility']);
        self::assertSame('Copper Kettle Brewing, Millbrook', $firstNight['location']);
        self::assertIsArray($firstNight['series']);
        self::assertSame($organizationId, $firstNight['series']['organizationId']);
        $this->createRound($browser, self::string($firstNight['competitionId']), ['name' => 'Night Round', 'startsAt' => '2026-11-02T19:00', 'minutesLimit' => 60, 'timezone' => 'America/New_York']);

        // --- 4. The round without a puzzle gets the puzzle of the 7 loose results - they join it - and moves --------

        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundWithoutPuzzle . '/puzzles', ['puzzleIds' => [self::LOOSE_PUZZLE]]);
        self::assertResponseIsSuccessful();
        self::assertSame(7, $round['resultsCount']);
        self::assertSame(0, self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . $editionA)['resultsWithoutRoundCount']);

        $julyContest = $this->createEdition($browser, $oldSeriesId, ['name' => 'Virtual Contest July 2026', 'dateFrom' => '2026-07-15', 'dateTo' => '2026-07-15']);
        $moved = self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . $roundWithoutPuzzle . '/move', ['competitionId' => $julyContest]);
        self::assertResponseIsSuccessful();
        self::assertSame($julyContest, $moved['competitionId']);
        self::assertSame(7, $moved['resultsCount']);

        $july = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . $julyContest);
        self::assertSame(1, $july['roundsCount']);
        self::assertSame(7, $july['resultsCount']);
        self::assertSame(0, $july['resultsWithoutRoundCount']);
        self::assertSame(7, $database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_id = :competitionId AND competition_round_id = :roundId',
            ['competitionId' => $julyContest, 'roundId' => $roundWithoutPuzzle],
        ));

        $a = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . $editionA);
        self::assertSame(5, $a['roundsCount']);
        self::assertSame(4, $a['resultsCount']);

        // --- 5. The other rounds move one by one into new editions; the old edition keeps its last round -----------

        foreach ($roundsWithResults as $index => $roundId) {
            $contest = $this->createEdition($browser, $oldSeriesId, [
                'name' => sprintf('Virtual Contest %d 2026', $index + 1),
                'dateFrom' => sprintf('2026-0%d-15', $index + 3),
                'dateTo' => sprintf('2026-0%d-15', $index + 3),
            ]);

            $moved = self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . $roundId . '/move', ['competitionId' => $contest]);
            self::assertResponseIsSuccessful();
            self::assertSame($contest, $moved['competitionId']);
            self::assertSame(1, $moved['resultsCount']);
            self::assertSame(1, self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . $contest)['resultsCount']);
        }

        // The old results address of a moved round leads to where it is now
        $browser->request('GET', '/en/series/quarry-hollow-virtual-contest/virtual-season/results/virtual-contest-1');
        self::assertResponseRedirects('/en/series/quarry-hollow-virtual-contest/virtual-contest-1-2026/results/virtual-contest-1', 301);

        $a = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . $editionA, [
            'name' => 'Virtual Contest January 2027',
            'slug' => 'virtual-contest-january-2027',
            'dateFrom' => '2027-01-20',
            'dateTo' => '2027-01-20',
            'registrationLink' => 'https://quarry-hollow.example/register/january',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('virtual-contest-january-2027', $a['slug']);
        self::assertSame('2027-01-20', $a['dateFrom']);
        self::assertSame(1, $a['roundsCount']);
        self::assertSame([$upcomingRound], array_column(self::list($a['rounds']), 'roundId'));
        self::assertSame(0, $a['resultsCount']);

        // The address the series had before it became the organization's still finds the renamed edition
        $browser->request('GET', '/en/series/' . self::OLD_SERIES_SLUG . '/virtual-season');
        self::assertResponseRedirects('/en/series/quarry-hollow-virtual-contest/virtual-contest-january-2027', 301);

        // --- 6. The other editions move to the new series and get one-day dates, names and addresses --------------

        $b = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . $editionB . '/move', ['seriesId' => $breweryNights]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($b['series']);
        self::assertSame($breweryNights, $b['series']['seriesId']);
        self::assertFalse($b['isOnline']);
        self::assertSame('Copper Kettle Brewing, Millbrook', $b['location']);

        $b = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . $editionB, [
            'name' => 'Copper Kettle Puzzle Night - October',
            'slug' => 'copper-kettle-october-2026',
            'dateFrom' => '2026-10-05',
            'dateTo' => '2026-10-05',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('copper-kettle-october-2026', $b['slug']);
        self::assertSame('2026-10-05', $b['dateTo']);
        self::assertSame('approved', $b['status']);

        $c = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . $editionC . '/move', ['seriesId' => $pubPuzzles]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($c['series']);
        self::assertSame($pubPuzzles, $c['series']['seriesId']);
        self::assertSame(1, $c['participantsCount']);
        // Its own place was set by hand - it stays
        self::assertSame('Millbrook Taproom', $c['location']);

        $c = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . $editionC, [
            'name' => 'Old Mill Pub Puzzle - November',
            'slug' => 'old-mill-november-2026',
            'dateFrom' => '2026-11-24',
            'dateTo' => '2026-11-24',
        ]);
        self::assertSame('old-mill-november-2026', $c['slug']);

        // Its address before the move leads to its page now, and so does the one before the organization existed
        $browser->request('GET', '/en/series/quarry-hollow-virtual-contest/pub-puzzle');
        self::assertResponseRedirects('/en/series/old-mill-pub-puzzle/old-mill-november-2026', 301);
        $browser->request('GET', '/en/series/' . self::OLD_SERIES_SLUG . '/pub-puzzle');
        self::assertResponseRedirects('/en/series/old-mill-pub-puzzle/old-mill-november-2026', 301);

        // --- The organization as it is now ----------------------------------------------------------------------------

        $organization = self::callInternalApi($browser, 'GET', '/internal-api/organizations/' . self::OLD_SERIES_SLUG);
        self::assertResponseIsSuccessful();
        $series = self::list($organization['series']);
        self::assertEqualsCanonicalizing([$oldSeriesId, $breweryNights, $pubPuzzles], array_column($series, 'seriesId'));

        $virtual = self::callInternalApi($browser, 'GET', '/internal-api/series/quarry-hollow-virtual-contest');
        $editions = self::list($virtual['editions']);
        self::assertCount(6, $editions);
        self::assertSame([1, 1, 1, 1, 1, 1], array_column($editions, 'roundsCount'));
        self::assertSame([0, 1, 1, 1, 1, 7], self::sorted(array_column($editions, 'resultsCount')));

        $pub = self::callInternalApi($browser, 'GET', '/internal-api/series/' . $pubPuzzles);
        self::assertSame([$editionC], array_column(self::list($pub['editions']), 'competitionId'));
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createEdition(KernelBrowser $browser, string $seriesId, array $fields): string
    {
        $edition = self::callInternalApi($browser, 'POST', '/internal-api/series/' . $seriesId . '/editions', $fields);
        self::assertResponseStatusCodeSame(201, json_encode($edition, JSON_THROW_ON_ERROR));

        return self::string($edition['competitionId']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createRound(KernelBrowser $browser, string $competitionId, array $fields): string
    {
        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . $competitionId . '/rounds', $fields);
        self::assertResponseStatusCodeSame(201, json_encode($round, JSON_THROW_ON_ERROR));

        return self::string($round['roundId']);
    }

    private function createSeries(KernelBrowser $browser, string $organizationId, string $name, string $location, string $schedule): string
    {
        $series = self::callInternalApi($browser, 'POST', '/internal-api/series', [
            'name' => $name,
            'organizationId' => $organizationId,
            'isOnline' => false,
            'location' => $location,
            'locationCountryCode' => 'us',
            'link' => 'https://quarry-hollow.example/nights',
            'eligibility' => '18+',
            'schedule' => $schedule,
            'description' => 'Casual puzzle nights - no results kept.',
        ]);
        self::assertResponseStatusCodeSame(201, json_encode($series, JSON_THROW_ON_ERROR));
        self::assertSame('approved', $series['status']);
        self::assertTrue($series['publiclyVisible']);
        self::assertSame($organizationId, $series['organizationId']);
        self::assertSame($schedule, $series['schedule']);
        self::assertSame('18+', $series['eligibility']);
        self::assertSame([], $series['editions']);

        return self::string($series['seriesId']);
    }

    private function addTime(string $userId, string $puzzleId, string $competitionId, string $time): void
    {
        $this->messageBus()->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
