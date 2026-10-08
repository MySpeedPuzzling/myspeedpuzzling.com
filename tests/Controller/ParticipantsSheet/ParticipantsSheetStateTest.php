<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The participants spreadsheet's read endpoints (contract §4.1, §4.2): the state JSON and the version, with the
 * official results API rules, and the privacy of linked profiles (O9) - a participant row is never hidden, the linked
 * profile's identity follows the viewer's own blocks and the private profile allow list.
 */
final class ParticipantsSheetStateTest extends WebTestCase
{
    private const string API = '/en/participants-sheet-api/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInIsToldToSignInNeverRedirected(): void
    {
        foreach (['/state', '/version'] as $endpoint) {
            $this->browser->request('GET', self::API . $endpoint);

            self::assertResponseStatusCodeSame(401);
            self::assertSame(['error' => 'sign_in_required'], $this->json());
        }
    }

    public function testSomebodyWhoDoesNotOrganiseTheEventIsForbidden(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['/state', '/version'] as $endpoint) {
            $this->browser->request('GET', self::API . $endpoint);

            self::assertResponseStatusCodeSame(403);
            self::assertSame(['error' => 'forbidden'], $this->json());
        }
    }

    public function testAnUnknownOrDeletedEventIsAJson404(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        foreach (['GET /state', 'GET /version', 'POST /registration'] as $request) {
            [$method, $endpoint] = explode(' ', $request);
            $this->browser->request($method, '/en/participants-sheet-api/018d0020-0000-0000-0000-000000009999' . $endpoint, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertSame(['error' => 'competition_not_found', 'message' => 'This event does not exist any more.'], $this->json());
        }
    }

    public function testTheVersionIsTheSheetsVersionAndMovesWithAChange(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::API . '/version');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $version = $this->json()['version'] ?? null;
        self::assertSame(self::getContainer()->get(GetParticipantsSheetVersion::class)->ofCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP), $version);
        self::assertSame(64, strlen($version));

        $this->database()->executeStatement("UPDATE competition_participant SET name = 'Ivan Renamed' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_IVAN]);

        $this->browser->request('GET', self::API . '/version');
        self::assertNotSame($version, $this->json()['version']);
    }

    public function testTheStateHasTheContractsShape(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $state = $this->state();

        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        self::assertSame(['serverNow', 'version', 'competition', 'rounds', 'people', 'places', 'teams', 'mercure'], array_keys($state));
        self::assertSame(self::getContainer()->get(GetParticipantsSheetVersion::class)->ofCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP), $state['version']);

        self::assertSame([
            'id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'name' => 'Results Cup',
            'isOnline' => false,
            'registrationManaged' => false,
            'capacity' => null,
            'eventUrl' => '/en/events/results-cup',
            'editUrl' => '/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP,
        ], $state['competition']);

        // By start
        $rounds = self::objects($state['rounds']);
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], array_column($rounds, 'name'));
        $groupA = self::find($rounds, OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame(['id', 'name', 'category', 'teamSize', 'startsAt', 'timezone', 'started', 'color', 'textColor', 'tableNumbersOff', 'piecesCount', 'resultsPublished', 'urls'], array_keys($groupA));
        self::assertSame('solo', $groupA['category']);
        self::assertNull($groupA['teamSize']);
        self::assertSame('Europe/Prague', $groupA['timezone']);
        self::assertTrue($groupA['started']);
        self::assertTrue($groupA['resultsPublished']);
        self::assertFalse($groupA['tableNumbersOff']);
        self::assertSame(1000, $groupA['piecesCount']);
        self::assertSame([
            'liveEntry' => '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A,
            'resultsDesk' => '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A,
            'seating' => '/en/round-seating/' . OfficialResultsFixture::ROUND_GROUP_A,
            'edit' => '/en/edit-event-round/' . OfficialResultsFixture::ROUND_GROUP_A,
        ], $groupA['urls']);
        self::assertSame('duo', self::find($rounds, OfficialResultsFixture::ROUND_PAIRS)['category']);
        self::assertNull(self::find($rounds, OfficialResultsFixture::ROUND_PAIRS_FINAL)['piecesCount'], 'The Pairs Final has no puzzle');

        // Every participant, by name
        $people = self::objects($state['people']);
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending', 'Gina Quick', 'Hugo Slow', 'Ivan Last'],
            array_column($people, 'name'),
        );
        $anna = self::find($people, OfficialResultsFixture::PARTICIPANT_ANNA);
        self::assertSame(['id', 'name', 'country', 'externalId', 'note', 'source', 'removedAt', 'connectedAt', 'player', 'registration', 'playerResultRounds'], array_keys($anna));
        self::assertSame('cz', $anna['country']);
        self::assertNull($anna['registration'], 'The event does not manage registration');
        self::assertSame([], $anna['playerResultRounds']);
        $annasPlayer = self::object($anna['player']);
        self::assertSame(['id', 'visible', 'name', 'code', 'country', 'avatar', 'profileUrl'], array_keys($annasPlayer));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $annasPlayer['id']);
        self::assertTrue($annasPlayer['visible']);
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_ADMIN, $annasPlayer['profileUrl']);
        self::assertNull(self::find($people, OfficialResultsFixture::PARTICIPANT_BEN)['player'], 'Ben is linked to nobody');

        // One place per person per round; the official record of a pair lives on the pair
        $places = self::objects($state['places']);
        self::assertCount(18, $places);
        $annaInGroupA = self::find($places, OfficialResultsFixture::ENTRY_A_ANNA);
        self::assertIsString($annaInGroupA['enteredAt']);
        self::assertSame([
            'id' => OfficialResultsFixture::ENTRY_A_ANNA,
            'participantId' => OfficialResultsFixture::PARTICIPANT_ANNA,
            'roundId' => OfficialResultsFixture::ROUND_GROUP_A,
            'teamId' => null,
            'table' => 1,
            'result' => ['seconds' => 3600],
            'qualified' => true,
            'enteredAt' => $annaInGroupA['enteredAt'],
            'enteredBy' => PlayerFixture::PLAYER_WITH_STRIPE_NAME,
        ], $annaInGroupA);
        self::assertSame(['piecesPlaced' => 850], self::find($places, OfficialResultsFixture::ENTRY_A_DAN)['result']);
        self::assertSame(['didNotStart' => true], self::find($places, OfficialResultsFixture::ENTRY_A_EVA)['result']);
        self::assertNull(self::find($places, OfficialResultsFixture::ENTRY_A_FILIP)['result']);

        $annaInPairs = array_values(array_filter($places, static fn (array $place): bool => $place['participantId'] === OfficialResultsFixture::PARTICIPANT_ANNA && $place['roundId'] === OfficialResultsFixture::ROUND_PAIRS));
        self::assertCount(1, $annaInPairs);
        self::assertSame(['teamId' => OfficialResultsFixture::TEAM_SHARKS, 'table' => null, 'result' => null, 'qualified' => false], array_intersect_key($annaInPairs[0], ['teamId' => true, 'table' => true, 'result' => true, 'qualified' => true]));

        $teams = self::objects($state['teams']);
        self::assertCount(4, $teams);
        $sharks = self::find($teams, OfficialResultsFixture::TEAM_SHARKS);
        self::assertSame(['id', 'roundId', 'name', 'table', 'result', 'qualified', 'enteredAt', 'enteredBy'], array_keys($sharks));
        self::assertSame('Puzzle Sharks', $sharks['name']);
        self::assertSame(1, $sharks['table']);
        self::assertSame(['seconds' => 5400], $sharks['result']);
        self::assertTrue($sharks['qualified']);
        self::assertNull(self::find($teams, OfficialResultsFixture::TEAM_UNNAMED)['name']);

        // The page follows the event's participants topic and every round's results topic
        $topics = self::object($state['mercure'])['topics'];
        self::assertIsArray($topics);
        self::assertContains('/competition-participants/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $topics);
        foreach ($rounds as $round) {
            self::assertIsString($round['id']);
            self::assertContains('/round-results/' . $round['id'], $topics);
        }
    }

    public function testAPrivateProfileIsWithheldButItsParticipantStays(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $gina = $this->person(OfficialResultsFixture::PARTICIPANT_GINA);

        self::assertSame('Gina Quick', $gina['name']);
        self::assertSame('us', $gina['country']);
        self::assertSame([
            'id' => PlayerFixture::PLAYER_PRIVATE,
            'visible' => false,
            'name' => null,
            'code' => null,
            'country' => null,
            'avatar' => null,
            'profileUrl' => null,
        ], $gina['player']);
    }

    public function testAPrivatePlayerWhoLetsTheViewerSeeThemIsShown(): void
    {
        $this->database()->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => PlayerFixture::PLAYER_PRIVATE, 'viewer' => self::ORGANISER],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $player = $this->person(OfficialResultsFixture::PARTICIPANT_GINA)['player'];

        self::assertIsArray($player);
        self::assertTrue($player['visible']);
        self::assertSame('player2', $player['code']);
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE, $player['profileUrl']);
    }

    public function testAPlayerTheViewerBlocksIsWithheldButTheirParticipantStays(): void
    {
        $this->block(blocker: self::ORGANISER, blocked: PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $hugo = $this->person(OfficialResultsFixture::PARTICIPANT_HUGO);

        self::assertSame('Hugo Slow', $hugo['name']);
        self::assertSame(['id' => PlayerFixture::PLAYER_REGULAR, 'visible' => false, 'name' => null, 'code' => null, 'country' => null, 'avatar' => null, 'profileUrl' => null], $hugo['player']);
        // Nothing else of the sheet changes - Hugo's places and his pair stay
        self::assertNotEmpty(array_filter(self::objects($this->state()['places']), static fn (array $place): bool => $place['participantId'] === OfficialResultsFixture::PARTICIPANT_HUGO));
    }

    /**
     * Blocks are one-directional: the blocked side must never be able to tell
     */
    public function testAPlayerWhoBlocksTheViewerIsShownAsUsual(): void
    {
        $this->block(blocker: PlayerFixture::PLAYER_REGULAR, blocked: self::ORGANISER);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $player = $this->person(OfficialResultsFixture::PARTICIPANT_HUGO)['player'];

        self::assertIsArray($player);
        self::assertTrue($player['visible']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $player['name']);
    }

    public function testNobodyIsPrivateToThemselves(): void
    {
        $this->database()->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::ORGANISER]);
        $this->database()->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => self::ORGANISER, 'id' => OfficialResultsFixture::PARTICIPANT_IVAN]);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $player = $this->person(OfficialResultsFixture::PARTICIPANT_IVAN)['player'];

        self::assertIsArray($player);
        self::assertTrue($player['visible']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $player['name']);
    }

    public function testTheRoundsWhereALinkedPlayerHasTimesOfTheirOwn(): void
    {
        // Hugo's player added a time of his own in Group B
        $puzzleId = $this->database()->fetchOne('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_GROUP_B]);
        self::assertIsString($puzzleId);
        $this->database()->executeStatement(
            'INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, verified, competition_id, competition_round_id)
             VALUES (:id, :player, :puzzle, 4000, NOW(), false, :competition, :round)',
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_REGULAR, 'puzzle' => $puzzleId, 'competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'round' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        self::assertSame([OfficialResultsFixture::ROUND_GROUP_B], $this->person(OfficialResultsFixture::PARTICIPANT_HUGO)['playerResultRounds']);
        self::assertSame([], $this->person(OfficialResultsFixture::PARTICIPANT_ANNA)['playerResultRounds']);
    }

    public function testRemovedPeopleAndTheirPlacesAreListed(): void
    {
        $this->database()->executeStatement('UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_FILIP]);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $filip = $this->person(OfficialResultsFixture::PARTICIPANT_FILIP);

        self::assertIsString($filip['removedAt']);
        self::assertNotEmpty(array_filter(self::objects($this->state()['places']), static fn (array $place): bool => $place['participantId'] === OfficialResultsFixture::PARTICIPANT_FILIP));
    }

    public function testManagedRegistrationComesWithEveryPerson(): void
    {
        $this->database()->executeStatement('UPDATE competition SET registration_managed = true, capacity = 8 WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        $this->database()->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted', registered_at = '2026-09-01 10:00:00' WHERE id = :id",
            ['id' => OfficialResultsFixture::PARTICIPANT_IVAN],
        );
        $this->database()->executeStatement(
            "UPDATE competition_participant SET registration_status = 'paid', paid_at = '2026-09-02 10:00:00', checked_in_at = '2026-09-03 08:00:00' WHERE id = :id",
            ['id' => OfficialResultsFixture::PARTICIPANT_ANNA],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $competition = self::object($this->state()['competition']);
        self::assertTrue($competition['registrationManaged']);
        self::assertSame(8, $competition['capacity']);

        self::assertSame(['status' => 'waitlisted', 'registeredAt' => '2026-09-01T10:00:00+00:00', 'paidAt' => null, 'checkedInAt' => null, 'waitlistPosition' => 1], $this->person(OfficialResultsFixture::PARTICIPANT_IVAN)['registration']);
        self::assertSame(['status' => 'paid', 'registeredAt' => null, 'paidAt' => '2026-09-02T10:00:00+00:00', 'checkedInAt' => '2026-09-03T08:00:00+00:00', 'waitlistPosition' => null], $this->person(OfficialResultsFixture::PARTICIPANT_ANNA)['registration']);
        // A row without a status holds a spot
        $ben = $this->person(OfficialResultsFixture::PARTICIPANT_BEN)['registration'];
        self::assertIsArray($ben);
        self::assertSame('reserved', $ben['status']);
    }

    /**
     * E-2: every waitlisted person's place in the line, counted by the server with GetEventAttendance's rule (the line
     * the waitlisted player sees): registered first comes first (none = first of all), the id decides within the same
     * second. Removed people and people holding a spot have none.
     * A registration action's answer (one person) counts over the whole line too.
     */
    public function testEveryWaitlistedPersonHasTheirPlaceInTheLine(): void
    {
        $this->database()->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        $waitlist = static fn (string $participantId, null|string $registeredAt, bool $removed = false): string => sprintf(
            "UPDATE competition_participant SET registration_status = 'waitlisted', registered_at = %s, deleted_at = %s WHERE id = '%s'",
            $registeredAt !== null ? "'" . $registeredAt . "'" : 'NULL',
            $removed ? 'NOW()' : 'NULL',
            $participantId,
        );
        $this->database()->executeStatement($waitlist(OfficialResultsFixture::PARTICIPANT_IVAN, '2026-09-01 10:00:00'));
        $this->database()->executeStatement($waitlist(OfficialResultsFixture::PARTICIPANT_BEN, '2026-09-01 10:00:00'));
        $this->database()->executeStatement($waitlist(OfficialResultsFixture::PARTICIPANT_GINA, '2026-09-01 09:59:59'));
        $this->database()->executeStatement($waitlist(OfficialResultsFixture::PARTICIPANT_FILIP, null));
        $this->database()->executeStatement($waitlist(OfficialResultsFixture::PARTICIPANT_EVA, '2026-08-01 10:00:00', removed: true));
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $positions = [];
        foreach (self::objects($this->state()['people']) as $person) {
            $registration = $person['registration'];
            self::assertIsArray($registration);
            self::assertIsString($person['id']);
            $positions[$person['id']] = $registration['waitlistPosition'];
        }

        // The same moment: the lower id first (Ben's id sorts before Ivan's)
        self::assertSame(1, $positions[OfficialResultsFixture::PARTICIPANT_FILIP]);
        self::assertSame(2, $positions[OfficialResultsFixture::PARTICIPANT_GINA]);
        self::assertSame(3, $positions[OfficialResultsFixture::PARTICIPANT_BEN]);
        self::assertSame(4, $positions[OfficialResultsFixture::PARTICIPANT_IVAN]);
        self::assertNull($positions[OfficialResultsFixture::PARTICIPANT_EVA], 'Removed');
        self::assertNull($positions[OfficialResultsFixture::PARTICIPANT_ANNA], 'Holds a spot');

        $ivan = self::getContainer()->get(GetParticipantsSheetState::class)->person(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::PARTICIPANT_IVAN, self::ORGANISER);
        self::assertSame(4, $ivan->registration?->waitlistPosition);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $this->browser->request('GET', self::API . '/state');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function person(string $participantId): array
    {
        return self::find(self::objects($this->state()['people']), $participantId);
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        return array_map(self::object(...), $value);
    }

    /**
     * @param list<array<string, mixed>> $objects
     * @return array<string, mixed>
     */
    private static function find(array $objects, string $id): array
    {
        foreach ($objects as $object) {
            if (($object['id'] ?? null) === $id) {
                return $object;
            }
        }

        self::fail('Nothing with the id ' . $id);
    }

    private function block(string $blocker, string $blocked): void
    {
        $this->database()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blocker, 'blocked' => $blocked],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
