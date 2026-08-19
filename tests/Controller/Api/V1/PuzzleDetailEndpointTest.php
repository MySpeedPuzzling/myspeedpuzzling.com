<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * GET /api/v1/puzzles/{puzzleId} - the puzzle detail is the catalog card of
 * one puzzle: the same fields, the same insight objects, the same gates per
 * token owner as GET /api/v1/puzzles (PuzzleSearchEndpointTest), built by the
 * same PuzzleResponseFactory.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE and PLAYER_ADMIN are
 * members; PLAYER_REGULAR is not. PLAYER_WITH_STRIPE has one solo solve of
 * PUZZLE_500_01 (2100 s) and a 500-piece baseline; PLAYER_REGULAR has three
 * solo solves of PUZZLE_500_02 (2200, 1900, 1700 s) and a duo solve of
 * PUZZLE_1000_01. PUZZLE_5000 has never been solved by anyone. Difficulty rows
 * are seeded per test - the intelligence recalculation is batch.
 *
 * @phpstan-type StatisticsGroup array{count: int, fastest_seconds: null|int, average_seconds: null|int, slowest_seconds: null|int}
 * @phpstan-type SolvesGroup array{count: int, best_time_seconds: null|int, last_time_seconds: null|int, first_solved_at: null|string, last_solved_at: null|string}
 * @phpstan-type Detail array{
 *     id: string,
 *     name: string,
 *     alternative_name: null|string,
 *     manufacturer: array{id: string, name: string},
 *     pieces_count: int,
 *     image: null|string,
 *     ean: null|string,
 *     identification_number: null|string,
 *     is_available: bool,
 *     is_approved: bool,
 *     statistics: array{solved_times: int, solo: StatisticsGroup, duo: StatisticsGroup, team: StatisticsGroup},
 *     difficulty: null|array{score: null|float, level: null|string, confidence: string, sample_size: int},
 *     prediction: null|array{predicted_seconds: null|int, range_low_seconds: null|int, range_high_seconds: null|int, is_personalized: bool, personal_solve_count: null|int, predicted_attempt_number: null|int, last_time_seconds: null|int},
 *     solves: null|array{solo: SolvesGroup, duo: SolvesGroup, team: SolvesGroup},
 * }
 */
final class PuzzleDetailEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/puzzles/';

    /** @var list<string> the JSON keys of the detail - exactly the card of GET /api/v1/puzzles, in the same order */
    private const array CARD_KEYS = ['id', 'name', 'alternative_name', 'manufacturer', 'pieces_count', 'image', 'ean', 'identification_number', 'is_available', 'is_approved', 'statistics', 'difficulty', 'prediction', 'solves'];

    private const array EMPTY_SOLVES_GROUP = ['count' => 0, 'best_time_seconds' => null, 'last_time_seconds' => null, 'first_solved_at' => null, 'last_solved_at' => null];

    private const array EMPTY_STATISTICS_GROUP = ['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null];

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * A machine token has no player behind it: catalog data and public
     * statistics, and every insight object null - accepted, never an error.
     */
    public function testClientCredentialsTokenGetsCatalogDataButNoInsights(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticateClientCredentials($browser);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $detail['id']);
        $this->assertSame('Puzzle 2', $detail['name']);
        $this->assertSame('4005556123456', $detail['ean']);
        $this->assertGreaterThan(0, $detail['statistics']['solved_times']);
        $this->assertGreaterThan(0, $detail['statistics']['solo']['count']);
        $this->assertNull($detail['difficulty']);
        $this->assertNull($detail['prediction']);
        $this->assertNull($detail['solves']);
    }

    /**
     * Member, but the token may not read results (profile:read only): the
     * difficulty is there, prediction and solves are results data and stay null.
     */
    public function testAuthorizationCodeMemberWithProfileScopeOnlyGetsDifficultyOnly(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame(['score' => 1.0263, 'level' => 'average', 'confidence' => 'medium', 'sample_size' => 20], $detail['difficulty']);
        $this->assertNull($detail['prediction']);
        $this->assertNull($detail['solves']);
    }

    public function testAuthorizationCodeMemberWithResultsScopeGetsAllThree(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame('average', $detail['difficulty']['level'] ?? null);
        $this->assertTrue($detail['prediction']['is_personalized'] ?? null);
        $this->assertSame(1, $detail['solves']['solo']['count'] ?? null);
    }

    /**
     * The full picture for a member's PAT, with the exact values: the seeded
     * difficulty, a personalized prediction from the one solo solve (2100 s),
     * and that solve in the solves object - solo only, duo and team empty.
     */
    public function testPersonalAccessTokenMemberGetsAllThreeWithExactValues(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame(['score' => 1.0263, 'level' => 'average', 'confidence' => 'medium', 'sample_size' => 20], $detail['difficulty']);

        $prediction = $detail['prediction'];
        $this->assertNotNull($prediction);
        $this->assertTrue($prediction['is_personalized']);
        $this->assertSame(1, $prediction['personal_solve_count']);
        $this->assertSame(2, $prediction['predicted_attempt_number']);
        $this->assertSame(2100, $prediction['last_time_seconds']);
        $this->assertIsInt($prediction['predicted_seconds']);
        $this->assertGreaterThan(0, $prediction['predicted_seconds']);
        $this->assertLessThanOrEqual($prediction['predicted_seconds'], $prediction['range_low_seconds']);
        $this->assertGreaterThanOrEqual($prediction['predicted_seconds'], $prediction['range_high_seconds']);

        $solves = $detail['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(1, $solves['solo']['count']);
        $this->assertSame(2100, $solves['solo']['best_time_seconds']);
        $this->assertSame(2100, $solves['solo']['last_time_seconds']);
        $this->assertIsString($solves['solo']['first_solved_at']);
        $this->assertSame($solves['solo']['first_solved_at'], $solves['solo']['last_solved_at']);
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['duo']);
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['team']);
    }

    /**
     * Not a member: no difficulty, no prediction - even with plenty of own
     * history to predict from - but the own solves are not members-only.
     */
    public function testNonMemberGetsSolvesButNoDifficultyOrPrediction(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertNull($detail['difficulty']);
        $this->assertNull($detail['prediction']);

        // three solo solves of PUZZLE_500_02: 2200 (20 days ago), 1900 (15), 1700 (10)
        $solves = $detail['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(3, $solves['solo']['count']);
        $this->assertSame(1700, $solves['solo']['best_time_seconds']);
        $this->assertSame(1700, $solves['solo']['last_time_seconds']);
        $this->assertIsString($solves['solo']['first_solved_at']);
        $this->assertIsString($solves['solo']['last_solved_at']);
        $this->assertLessThan(
            new DateTimeImmutable($solves['solo']['last_solved_at']),
            new DateTimeImmutable($solves['solo']['first_solved_at']),
        );
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['duo']);
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['team']);

        // the same non-member through an authorization-code token with results:read
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);
        $this->assertNull($detail['difficulty']);
        $this->assertNull($detail['prediction']);
        $this->assertSame(3, $detail['solves']['solo']['count'] ?? null);
    }

    /**
     * The "time predictions" opt-out hides the prediction only - difficulty and
     * solves stay, the same split as on the puzzle page.
     */
    public function testOptedOutMemberGetsDifficultyAndSolvesButNoPrediction(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame('average', $detail['difficulty']['level'] ?? null);
        $this->assertNull($detail['prediction']);
        $this->assertSame(1, $detail['solves']['solo']['count'] ?? null);
    }

    /**
     * A member on a puzzle nobody has scored or solved (5000 pieces: no own
     * history, no baseline, no difficulty row): every object is present -
     * difficulty "insufficient", a prediction with null fields, zero solves.
     * null would mean "not entitled", and a member is entitled.
     */
    public function testMemberWithoutHistoryOrBaselineGetsEmptyObjectsNotNulls(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_5000);

        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $this->assertSame(5000, $detail['pieces_count']);
        $this->assertSame(['score' => null, 'level' => null, 'confidence' => 'insufficient', 'sample_size' => 0], $detail['difficulty']);
        $this->assertSame(
            ['predicted_seconds' => null, 'range_low_seconds' => null, 'range_high_seconds' => null, 'is_personalized' => false, 'personal_solve_count' => null, 'predicted_attempt_number' => null, 'last_time_seconds' => null],
            $detail['prediction'],
        );
        $this->assertSame(['solo' => self::EMPTY_SOLVES_GROUP, 'duo' => self::EMPTY_SOLVES_GROUP, 'team' => self::EMPTY_SOLVES_GROUP], $detail['solves']);
        $this->assertSame(
            ['solved_times' => 0, 'solo' => self::EMPTY_STATISTICS_GROUP, 'duo' => self::EMPTY_STATISTICS_GROUP, 'team' => self::EMPTY_STATISTICS_GROUP],
            $detail['statistics'],
        );
    }

    public function testStatisticsAreSplitByDiscipline(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        // PUZZLE_1000_01: solo solves plus one duo solve (team-001, 3600 s) - the duo never leaks into solo
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_1000_01);
        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);
        $statistics = $detail['statistics'];

        $this->assertSame($statistics['solo']['count'] + $statistics['duo']['count'] + $statistics['team']['count'], $statistics['solved_times']);
        $this->assertGreaterThan(0, $statistics['solo']['count']);
        $this->assertSame(['count' => 1, 'fastest_seconds' => 3600, 'average_seconds' => 3600, 'slowest_seconds' => 3600], $statistics['duo']);
        $this->assertSame(self::EMPTY_STATISTICS_GROUP, $statistics['team']);

        // and the owner's own duo solve is a duo in "solves" too
        $solves = $detail['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(0, $solves['solo']['count']);
        $this->assertSame(1, $solves['duo']['count']);
        $this->assertSame(3600, $solves['duo']['best_time_seconds']);

        // PUZZLE_500_01: eleven solo solves of several players (20-50 min), nothing in duo / team
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
        $statistics = $this->decode($browser)['statistics'];
        $this->assertSame(11, $statistics['solved_times']);
        $this->assertSame(11, $statistics['solo']['count']);
        $this->assertSame(1200, $statistics['solo']['fastest_seconds']);
        $this->assertSame(3000, $statistics['solo']['slowest_seconds']);
        $this->assertSame(self::EMPTY_STATISTICS_GROUP, $statistics['duo']);
        $this->assertSame(self::EMPTY_STATISTICS_GROUP, $statistics['team']);
    }

    /**
     * The detail is exactly the card of GET /api/v1/puzzles - field names, order
     * and values - for the same token: both are built by PuzzleResponseFactory.
     */
    public function testDetailIsTheCatalogCard(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->resetSearchRateLimit($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
        $detail = $this->decodeJson($browser);
        $this->assertSame(self::CARD_KEYS, array_keys($detail));

        $browser->request('GET', '/api/v1/puzzles', ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();
        $list = $this->decodeJson($browser);
        $this->assertIsArray($list['puzzles']);
        $this->assertCount(1, $list['puzzles']);

        $this->assertSame($list['puzzles'][0], $detail, 'The detail and the search card of the same puzzle are the same object for the same token');

        // the catalog fields themselves
        $typed = $this->decode($browser, $detail);
        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $typed['id']);
        $this->assertSame('Puzzle 1', $typed['name']);
        $this->assertNull($typed['alternative_name']);
        $this->assertSame(['id' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 'name' => 'Ravensburger'], $typed['manufacturer']);
        $this->assertSame(500, $typed['pieces_count']);
        $this->assertNull($typed['ean']);
        $this->assertSame('RB-500-001', $typed['identification_number']);
        $this->assertTrue($typed['is_available']);
        $this->assertTrue($typed['is_approved']);
    }

    /**
     * The flat GET /me/puzzles/{id}/predicted-time (the first Insights surface,
     * kept as it is) is a projection of the very same objects: the numbers agree.
     */
    public function testFlatPredictedTimeEndpointAgreesWithTheDetail(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
        $detail = $this->decode($browser);

        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');
        $this->assertResponseIsSuccessful();
        /** @var array{predicted_seconds: int, range_low_seconds: int, range_high_seconds: int, is_personalized: bool, personal_solve_count: int, last_time_seconds: int, difficulty_score: float, difficulty_level: string, difficulty_confidence: string} $flat */
        $flat = $this->decodeJson($browser);

        $this->assertNotNull($detail['prediction']);
        $this->assertNotNull($detail['difficulty']);
        $this->assertSame($flat['predicted_seconds'], $detail['prediction']['predicted_seconds']);
        $this->assertSame($flat['range_low_seconds'], $detail['prediction']['range_low_seconds']);
        $this->assertSame($flat['range_high_seconds'], $detail['prediction']['range_high_seconds']);
        $this->assertSame($flat['is_personalized'], $detail['prediction']['is_personalized']);
        $this->assertSame($flat['personal_solve_count'], $detail['prediction']['personal_solve_count']);
        $this->assertSame($flat['last_time_seconds'], $detail['prediction']['last_time_seconds']);
        $this->assertSame($flat['difficulty_score'], $detail['difficulty']['score']);
        $this->assertSame($flat['difficulty_level'], $detail['difficulty']['level']);
        $this->assertSame($flat['difficulty_confidence'], $detail['difficulty']['confidence']);
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_1000_03, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->decode($browser)['image']);

        // a released image is returned as stored
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_1000_03);
        $this->assertResponseIsSuccessful();
        $this->assertSame('puzzles/test/other.jpg', $this->decode($browser)['image']);

        // and so is the embargoed one once its embargo has passed
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('-1 hour'));
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $this->assertSame('puzzles/test/box.jpg', $this->decode($browser)['image']);
    }

    /**
     * A secret competition puzzle (hide_until in the future) does not exist for
     * the API - stricter than the website's puzzle page, which shows it by id.
     */
    public function testHiddenPuzzleIsNotFound(): void
    {
        $browser = self::createClient();
        $this->hidePuzzleUntil($browser, PuzzleFixture::PUZZLE_500_02, new DateTimeImmutable('+30 days'));

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // for a machine token too
        $this->authenticateClientCredentials($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // and it is back once the embargo has passed
        $this->hidePuzzleUntil($browser, PuzzleFixture::PUZZLE_500_02, new DateTimeImmutable('-1 day'));
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $this->decode($browser)['id']);
    }

    public function testUnknownOrMalformedIdIsNotFound(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT . '00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $browser->request('GET', self::ENDPOINT . 'not-a-uuid');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // an unapproved puzzle is still a puzzle (the catalog search lists it too), only hide_until hides
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_UNAPPROVED);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->decode($browser)['is_approved']);
    }

    /**
     * Fixed query cost per request (docs/features/api/v1-expansion-plan.md §5,
     * N3). Measured (2026-08-19): authentication 0-1 query for a
     * client_credentials token (the revocation check), 1 for a PAT, 3 for an
     * authorization-code token (access token, player, consent usage); then the
     * overview (1) and statistics (1); a token with a player behind it adds the
     * owner's profile (1) and, with PAT / results:read, solves (1); a member adds
     * difficulty (1) and the prediction (3 queries for a puzzle the owner has
     * solved, 4 for one the owner has not - the statistical path). That is
     * cc 2-3, non-member 5 (PAT) / 7 (OAuth2), member 9-10 (PAT) / 11-12 (OAuth2).
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_04, 1.30, MetricConfidence::Low);

        $this->authenticateClientCredentials($browser);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 4, 'client_credentials token');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'non-member PAT');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_02);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'non-member authorization-code token');

        // member PAT: a puzzle the owner has solved (personal prediction) ...
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'member PAT, solved puzzle');

        // ... and one the owner has not (statistical prediction: one query more)
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_04);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'member PAT, unsolved puzzle');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT . PuzzleFixture::PUZZLE_500_04);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token, unsolved puzzle');
    }

    public function testOpenApiDocumentsTheEndpoint(): void
    {
        $browser = self::createClient();

        $document = $this->openApiDocument($browser);
        $path = '/api/v1/puzzles/{puzzleId}';

        self::assertIsArray($document['paths'] ?? null);
        self::assertArrayHasKey($path, $document['paths'], sprintf('OpenAPI does not document %s', $path));

        /** @var array{get: array{tags: list<string>, summary: string, description: string, parameters: list<array{name: string, in: string, required?: bool}>, responses: array<int|string, mixed>}} $pathItem */
        $pathItem = $document['paths'][$path];
        $this->assertSame(['Puzzles'], $pathItem['get']['tags']);
        $this->assertNotSame('', $pathItem['get']['summary']);
        $this->assertStringContainsString('difficulty', $pathItem['get']['description']);
        $this->assertArrayHasKey('200', $pathItem['get']['responses']);
        $this->assertArrayHasKey('401', $pathItem['get']['responses']);
        $this->assertArrayHasKey('404', $pathItem['get']['responses']);

        $pathParameters = array_values(array_filter($pathItem['get']['parameters'], static fn (array $parameter): bool => $parameter['in'] === 'path'));
        $this->assertCount(1, $pathParameters);
        $this->assertSame('puzzleId', $pathParameters[0]['name']);
        $this->assertTrue($pathParameters[0]['required'] ?? false);
    }

    private function authenticatePat(KernelBrowser $browser, string $playerId): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));
    }

    /**
     * @param array<non-empty-string> $scopes
     */
    private function authenticateOAuth2(KernelBrowser $browser, string $playerId, array $scopes): void
    {
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            $playerId,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function authenticateClientCredentials(KernelBrowser $browser): void
    {
        // sub = aud = client id is exactly what the client_credentials grant issues
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            ['profile:read', 'results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    /**
     * The search endpoint is rate-limited per owner and its store is not
     * rolled back between tests - start with a full budget for this owner.
     */
    private function resetSearchRateLimit(KernelBrowser $browser, string $ownerKey): void
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = $container->get('limiter.api_puzzle_search');
        $limiter->create($ownerKey)->reset();
    }

    private function seedDifficulty(KernelBrowser $browser, string $puzzleId, float $score, MetricConfidence $confidence): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        // The incremental recalculation may already have left a row behind - reuse it,
        // the puzzle id is the primary key.
        $difficulty = $entityManager->find(PuzzleDifficulty::class, $puzzleId) ?? new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, 20, new DateTimeImmutable());

        $entityManager->persist($difficulty);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function hidePuzzleUntil(KernelBrowser $browser, string $puzzleId, DateTimeImmutable $until): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $puzzle->hideUntil = $until;
        $entityManager->flush();
        $entityManager->clear();
    }

    private function setImage(KernelBrowser $browser, string $puzzleId, string $image, null|DateTimeImmutable $hideUntil): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $puzzle->image = $image;
        $puzzle->hideImageUntil = $hideUntil;
        $entityManager->flush();
        $entityManager->clear();
    }

    private function optOutOfTimePredictions(KernelBrowser $browser, string $playerId): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        $player->changeTimePredictionsOptedOut(true);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * The successful detail response (or an already decoded one, typed).
     *
     * @param null|array<string, mixed> $decoded
     *
     * @return Detail
     */
    private function decode(KernelBrowser $browser, null|array $decoded = null): array
    {
        /** @var Detail $detail */
        $detail = $decoded ?? $this->decodeJson($browser);

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
