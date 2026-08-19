<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * GET /api/v1/puzzles - catalog search and barcode lookup, with the puzzle
 * cards' insight objects gated per token owner exactly as on the website.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE and PLAYER_ADMIN are
 * members; PLAYER_REGULAR is not. PLAYER_WITH_STRIPE has one solo solve of
 * PUZZLE_500_01 (2100 s); PLAYER_REGULAR has three solo solves of PUZZLE_500_02
 * (2200, 1900, 1700 s) and a duo solve of PUZZLE_1000_01. PUZZLE_500_02 carries
 * the EAN 4005556123456, PUZZLE_500_01 the identification number RB-500-001.
 * Difficulty rows are seeded per test - the intelligence recalculation is batch.
 *
 * @phpstan-type StatisticsGroup array{count: int, fastest_seconds: null|int, average_seconds: null|int, slowest_seconds: null|int}
 * @phpstan-type SolvesGroup array{count: int, best_time_seconds: null|int, last_time_seconds: null|int, first_solved_at: null|string, last_solved_at: null|string}
 * @phpstan-type Card array{
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
 * @phpstan-type ListResponse array{count: int, total: int, page: int, limit: int, has_more: bool, puzzles: list<Card>}
 * @phpstan-type Problem array{detail: string, violations?: list<array{propertyPath: string, message: string}>}
 */
final class PuzzleSearchEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/puzzles';

    /** @var list<string> */
    private const array PARAMETER_NAMES = ['query', 'ean', 'manufacturer', 'pieces_min', 'pieces_max', 'sort', 'difficulty', 'page', 'limit'];

    /** @var list<string> every limiter key a test of this class consumes from */
    private const array LIMITER_KEYS = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_WITH_STRIPE,
        PlayerFixture::PLAYER_ADMIN,
        OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
    ];

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $browser = $this->createApiClient();

        $browser->request('GET', self::ENDPOINT);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPersonalAccessTokenCanSearch(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['query' => 'Puzzle 1']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertGreaterThan(0, $response['count']);
        $this->assertSame(1, $response['page']);
        $this->assertSame(20, $response['limit']);
    }

    public function testAuthorizationCodeTokenWithProfileScopeOnlyCanSearch(): void
    {
        $browser = $this->createApiClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', self::ENDPOINT, ['query' => 'Puzzle 1']);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $this->decode($browser)['count']);
    }

    /**
     * A machine token has no player behind it: it sees the catalog, and every
     * insight object is null - accepted, never an error, so one client code
     * path works for every token.
     */
    public function testClientCredentialsTokenSearchesButGetsNoInsights(): void
    {
        $browser = $this->createApiClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticateClientCredentials($browser);

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(1, $response['count']);
        $puzzle = $response['puzzles'][0];
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $puzzle['id']);
        $this->assertNull($puzzle['difficulty']);
        $this->assertNull($puzzle['prediction']);
        $this->assertNull($puzzle['solves']);
    }

    public function testQueryFindsPuzzlesByName(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['query' => 'Puzzle 1', 'limit' => 100]);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $ids = $this->ids($response);
        $this->assertContains(PuzzleFixture::PUZZLE_500_01, $ids); // "Puzzle 1"
        $this->assertContains(PuzzleFixture::PUZZLE_1000_05, $ids); // "Puzzle 10"
        $this->assertNotContains(PuzzleFixture::PUZZLE_500_02, $ids); // "Puzzle 2"

        foreach ($response['puzzles'] as $puzzle) {
            $this->assertStringContainsStringIgnoringCase('Puzzle 1', $puzzle['name']);
        }
    }

    public function testQueryMatchesIdentificationNumberAndIsAccentInsensitive(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();
        $this->assertSame([PuzzleFixture::PUZZLE_500_01], $this->ids($this->decode($browser)));

        // "Püzzle 1" finds "Puzzle 1" - the same accent-insensitive matching as the website search box
        $browser->request('GET', self::ENDPOINT, ['query' => 'Püzzle 1', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertContains(PuzzleFixture::PUZZLE_500_01, $this->ids($this->decode($browser)));
    }

    public function testEanLookupIsExactWithZeroTolerance(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(1, $response['count']);
        $this->assertSame(1, $response['total']);
        $this->assertFalse($response['has_more']);
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $response['puzzles'][0]['id']);
        $this->assertSame('4005556123456', $response['puzzles'][0]['ean']);

        // a leading zero (UPC-A read as EAN-13) still finds it
        $browser->request('GET', self::ENDPOINT, ['ean' => '04005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertSame([PuzzleFixture::PUZZLE_500_02], $this->ids($this->decode($browser)));

        // a barcode nobody has registered
        $browser->request('GET', self::ENDPOINT, ['ean' => '9999999999999']);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(0, $response['count']);
        $this->assertSame(0, $response['total']);
        $this->assertSame([], $response['puzzles']);
    }

    public function testEanCannotBeCombinedWithOtherFilters(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456', 'query' => 'Puzzle']);

        $this->assertUnprocessableWithViolationOn($browser, 'ean');
        $this->assertStringContainsString('query', $this->decodeProblem($browser)['violations'][0]['message'] ?? '');

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456', 'sort' => 'a-z']);
        $this->assertUnprocessableWithViolationOn($browser, 'ean');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'query too short' => [['query' => 'a'], 'query'];
        yield 'query too long' => [['query' => str_repeat('x', 101)], 'query'];
        yield 'query whitespace only counts as too short' => [['query' => '   a   '], 'query'];
        yield 'ean with letters' => [['ean' => '40055561234ab'], 'ean'];
        yield 'ean too short' => [['ean' => '1234567'], 'ean'];
        yield 'ean too long' => [['ean' => '123456789012345'], 'ean'];
        yield 'manufacturer not a uuid' => [['manufacturer' => 'ravensburger'], 'manufacturer'];
        yield 'pieces_min below 1' => [['pieces_min' => '0'], 'pieces_min'];
        yield 'pieces_min not an integer' => [['pieces_min' => 'abc'], 'pieces_min'];
        yield 'pieces_max above 50000' => [['pieces_max' => '50001'], 'pieces_max'];
        yield 'sort unknown' => [['sort' => 'newest'], 'sort'];
        yield 'difficulty unknown tier' => [['difficulty' => 'impossible'], 'difficulty'];
        yield 'difficulty one unknown tier in a list' => [['difficulty' => 'hard,impossible'], 'difficulty'];
        yield 'page below 1' => [['page' => '0'], 'page'];
        yield 'page above 500' => [['page' => '501'], 'page'];
        yield 'page not an integer' => [['page' => 'two'], 'page'];
        yield 'limit below 1' => [['limit' => '0'], 'limit'];
        yield 'limit above 100' => [['limit' => '101'], 'limit'];
        yield 'limit not an integer' => [['limit' => '2.5'], 'limit'];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('invalidParameters')]
    public function testInvalidParameterIsRejected(array $parameters, string $expectedPropertyPath): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, $parameters);

        $this->assertUnprocessableWithViolationOn($browser, $expectedPropertyPath);
    }

    public function testPiecesMinAbovePiecesMaxIsRejected(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '1000', 'pieces_max' => '500']);

        $this->assertUnprocessableWithViolationOn($browser, 'pieces_min');
    }

    public function testPiecesRangeFiltersByPieceCount(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        // exact count = both bounds equal
        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '500', 'pieces_max' => '500', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertGreaterThanOrEqual(5, $response['count']);
        foreach ($response['puzzles'] as $puzzle) {
            $this->assertSame(500, $puzzle['pieces_count']);
        }

        // open-ended range
        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '2000', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertGreaterThan(0, $response['count']);
        foreach ($response['puzzles'] as $puzzle) {
            $this->assertGreaterThanOrEqual(2000, $puzzle['pieces_count']);
        }
    }

    public function testManufacturerFilter(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['manufacturer' => ManufacturerFixture::MANUFACTURER_TREFL, 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertGreaterThan(0, $response['count']);
        foreach ($response['puzzles'] as $puzzle) {
            $this->assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle['manufacturer']['id']);
            $this->assertSame('Trefl', $puzzle['manufacturer']['name']);
        }

        // unknown brand: empty result, not 404
        $browser->request('GET', self::ENDPOINT, ['manufacturer' => '00000000-0000-0000-0000-000000000000']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->decode($browser)['total']);
    }

    public function testDifficultySortRequiresMembership(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['sort' => 'easiest']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertStringContainsString('membership', $this->decodeProblem($browser)['detail']);

        $browser->request('GET', self::ENDPOINT, ['sort' => 'hardest']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // a machine token is never a member either
        $this->authenticateClientCredentials($browser);
        $browser->request('GET', self::ENDPOINT, ['sort' => 'easiest']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // the non-premium sorts are for everyone
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::ENDPOINT, ['sort' => 'a-z']);
        $this->assertResponseIsSuccessful();
    }

    public function testMemberCanSortByDifficulty(): void
    {
        $browser = $this->createApiClient();
        // Other fixture puzzles carry scores around 1.0 - these three sit clearly below and above them
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 0.50, MetricConfidence::High);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_03, 0.60, MetricConfidence::High);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 5.00, MetricConfidence::High);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT, ['sort' => 'easiest', 'limit' => 2]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame([PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03], $this->ids($response));
        $this->assertSame(0.5, $response['puzzles'][0]['difficulty']['score'] ?? null);

        $browser->request('GET', self::ENDPOINT, ['sort' => 'hardest', 'limit' => 2]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $this->ids($response)[0]);
        $this->assertSame('very_hard', $response['puzzles'][0]['difficulty']['level'] ?? null);
    }

    public function testDifficultyFilterRequiresMembership(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['difficulty' => 'hard']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertStringContainsString('membership', $this->decodeProblem($browser)['detail']);
    }

    public function testMemberCanFilterByDifficulty(): void
    {
        $browser = $this->createApiClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.30, MetricConfidence::High); // hard
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.50, MetricConfidence::High); // very_hard
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_03, 0.70, MetricConfidence::High); // very_easy
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT, ['difficulty' => 'hard,very_hard', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $ids = $this->ids($response);
        $this->assertContains(PuzzleFixture::PUZZLE_500_01, $ids);
        $this->assertContains(PuzzleFixture::PUZZLE_500_02, $ids);
        $this->assertNotContains(PuzzleFixture::PUZZLE_500_03, $ids);

        foreach ($response['puzzles'] as $puzzle) {
            $this->assertContains($puzzle['difficulty']['level'] ?? null, ['hard', 'very_hard']);
        }
    }

    public function testPaginationOverLimitTwo(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['manufacturer' => ManufacturerFixture::MANUFACTURER_TREFL, 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $all = $this->decode($browser);
        $total = $all['total'];
        $this->assertGreaterThan(2, $total);
        $this->assertSame($total, $all['count']);

        $seen = [];
        $pages = (int) ceil($total / 2);

        for ($page = 1; $page <= $pages; $page++) {
            $browser->request('GET', self::ENDPOINT, ['manufacturer' => ManufacturerFixture::MANUFACTURER_TREFL, 'limit' => 2, 'page' => $page]);
            $this->assertResponseIsSuccessful();
            $response = $this->decode($browser);

            $this->assertSame($page, $response['page']);
            $this->assertSame(2, $response['limit']);
            $this->assertSame($total, $response['total']);
            $this->assertSame($page < $pages, $response['has_more'], "has_more on page {$page}");
            $this->assertSame($page < $pages ? 2 : $total - 2 * ($pages - 1), $response['count']);

            $seen = [...$seen, ...$this->ids($response)];
        }

        $this->assertSame($this->ids($all), $seen, 'Pages walk the same list as one big page');

        // past the end: empty page, same total
        $browser->request('GET', self::ENDPOINT, ['manufacturer' => ManufacturerFixture::MANUFACTURER_TREFL, 'limit' => 2, 'page' => $pages + 1]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(0, $response['count']);
        $this->assertSame($total, $response['total']);
        $this->assertFalse($response['has_more']);
    }

    public function testLimitAboveHundredIsRejected(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['limit' => '101']);
        $this->assertUnprocessableWithViolationOn($browser, 'limit');

        $browser->request('GET', self::ENDPOINT, ['limit' => '100']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(100, $this->decode($browser)['limit']);
    }

    /**
     * A secret competition puzzle (hide_until in the future) does not exist for
     * the API: not in the listing, not by name, not by barcode.
     */
    public function testHiddenPuzzleIsNeverReturned(): void
    {
        $browser = $this->createApiClient();
        $this->hidePuzzleUntil($browser, PuzzleFixture::PUZZLE_500_02, new DateTimeImmutable('+30 days'));
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['query' => 'Puzzle 2', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertNotContains(PuzzleFixture::PUZZLE_500_02, $this->ids($this->decode($browser)));

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->decode($browser)['total']);

        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertNotContains(PuzzleFixture::PUZZLE_500_02, $this->ids($response));
        $this->assertLessThanOrEqual(100, $response['count']);

        // and it is back once the embargo has passed
        $this->hidePuzzleUntil($browser, PuzzleFixture::PUZZLE_500_02, new DateTimeImmutable('-1 day'));
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertSame([PuzzleFixture::PUZZLE_500_02], $this->ids($this->decode($browser)));
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = $this->createApiClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_1000_03, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->decode($browser)['puzzles'][0]['image']);

        $browser->request('GET', self::ENDPOINT, ['query' => 'Puzzle 2', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->puzzle($this->decode($browser), PuzzleFixture::PUZZLE_500_02)['image']);

        // a released image is returned as stored
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556789012']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('puzzles/test/other.jpg', $this->decode($browser)['puzzles'][0]['image']);
    }

    public function testPuzzleCardShape(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();

        // field names and order, on the raw JSON
        $raw = $this->decodeJson($browser);
        $this->assertSame(['count', 'total', 'page', 'limit', 'has_more', 'puzzles'], array_keys($raw));
        $card = $this->firstRawCard($browser);
        $this->assertSame(
            ['id', 'name', 'alternative_name', 'manufacturer', 'pieces_count', 'image', 'ean', 'identification_number', 'is_available', 'is_approved', 'statistics', 'difficulty', 'prediction', 'solves'],
            array_keys($card),
        );
        $rawStatistics = $card['statistics'];
        $this->assertIsArray($rawStatistics);
        $this->assertSame(['solved_times', 'solo', 'duo', 'team'], array_keys($rawStatistics));
        $this->assertSame(['count', 'fastest_seconds', 'average_seconds', 'slowest_seconds'], $this->keys($rawStatistics['solo']));

        $puzzle = $this->decode($browser)['puzzles'][0];
        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $puzzle['id']);
        $this->assertSame('Puzzle 1', $puzzle['name']);
        $this->assertNull($puzzle['alternative_name']);
        $this->assertSame(['id' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 'name' => 'Ravensburger'], $puzzle['manufacturer']);
        $this->assertSame(500, $puzzle['pieces_count']);
        $this->assertNull($puzzle['ean']);
        $this->assertSame('RB-500-001', $puzzle['identification_number']);
        $this->assertTrue($puzzle['is_available']);
        $this->assertTrue($puzzle['is_approved']);

        // community statistics, always split by discipline, from the precomputed puzzle_statistics row
        $statistics = $puzzle['statistics'];
        // PUZZLE_500_01: eleven solo solves of several players (20-50 min), nothing in duo / team
        $this->assertSame(11, $statistics['solved_times']);
        $this->assertSame(11, $statistics['solo']['count']);
        $this->assertSame(1200, $statistics['solo']['fastest_seconds']);
        $this->assertSame(3000, $statistics['solo']['slowest_seconds']);
        $this->assertIsInt($statistics['solo']['average_seconds']);
        $this->assertGreaterThan(1200, $statistics['solo']['average_seconds']);
        $this->assertLessThan(3000, $statistics['solo']['average_seconds']);
        $this->assertSame(['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null], $statistics['duo']);
        $this->assertSame(['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null], $statistics['team']);
    }

    public function testStatisticsAreSplitByDiscipline(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        // PUZZLE_1000_01: solo solves plus one duo solve (team-001, 3600 s) - the duo never leaks into solo
        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-1000-001']);
        $this->assertResponseIsSuccessful();
        $statistics = $this->decode($browser)['puzzles'][0]['statistics'];

        $this->assertSame($statistics['solo']['count'] + $statistics['duo']['count'] + $statistics['team']['count'], $statistics['solved_times']);
        $this->assertGreaterThan(0, $statistics['solo']['count']);
        $this->assertSame(['count' => 1, 'fastest_seconds' => 3600, 'average_seconds' => 3600, 'slowest_seconds' => 3600], $statistics['duo']);
        $this->assertSame(0, $statistics['team']['count']);

        // a puzzle nobody has solved: no statistics row, zeros and nulls
        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '9000', 'pieces_max' => '9000']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            [
                'solved_times' => 0,
                'solo' => ['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null],
                'duo' => ['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null],
                'team' => ['count' => 0, 'fastest_seconds' => null, 'average_seconds' => null, 'slowest_seconds' => null],
            ],
            $this->decode($browser)['puzzles'][0]['statistics'],
        );
    }

    public function testMemberSeesDifficultyAndNonMemberDoesNot(): void
    {
        $browser = $this->createApiClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20],
            $this->decode($browser)['puzzles'][0]['difficulty'],
        );

        // a member looking at a puzzle without a difficulty row: "insufficient data", not "members only"
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556789012']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['score' => null, 'level' => null, 'confidence' => 'insufficient', 'sample_size' => 0],
            $this->decode($browser)['puzzles'][0]['difficulty'],
        );

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->decode($browser)['puzzles'][0]['difficulty']);
    }

    public function testPredictionIsPersonalizedForMemberWithHistory(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            ['predicted_seconds', 'range_low_seconds', 'range_high_seconds', 'is_personalized', 'personal_solve_count', 'predicted_attempt_number', 'last_time_seconds'],
            $this->keys($this->firstRawCard($browser)['prediction']),
        );

        $prediction = $this->decode($browser)['puzzles'][0]['prediction'];
        $this->assertNotNull($prediction);
        $this->assertTrue($prediction['is_personalized']);
        $this->assertSame(1, $prediction['personal_solve_count']);
        $this->assertSame(2, $prediction['predicted_attempt_number']);
        $this->assertSame(2100, $prediction['last_time_seconds']);
        $this->assertIsInt($prediction['predicted_seconds']);
        $this->assertGreaterThan(0, $prediction['predicted_seconds']);
        $this->assertLessThanOrEqual($prediction['predicted_seconds'], $prediction['range_low_seconds']);
        $this->assertGreaterThanOrEqual($prediction['predicted_seconds'], $prediction['range_high_seconds']);
    }

    public function testPredictionIsNullWhenNotEntitled(): void
    {
        $browser = $this->createApiClient();

        // not a member (PLAYER_REGULAR has plenty of history on PUZZLE_500_02)
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->decode($browser)['puzzles'][0]['prediction']);

        // member, but the token may not read results (profile:read only) - prediction and solves are results data
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();
        $puzzle = $this->decode($browser)['puzzles'][0];
        $this->assertNull($puzzle['prediction']);
        $this->assertNull($puzzle['solves']);
        $this->assertIsArray($puzzle['difficulty']);

        // member with results:read: both objects are present
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();
        $puzzle = $this->decode($browser)['puzzles'][0];
        $this->assertTrue($puzzle['prediction']['is_personalized'] ?? null);
        $this->assertSame(1, $puzzle['solves']['solo']['count'] ?? null);

        // member who opted out of time predictions: difficulty and solves stay, prediction goes
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-500-001']);
        $this->assertResponseIsSuccessful();
        $puzzle = $this->decode($browser)['puzzles'][0];
        $this->assertNull($puzzle['prediction']);
        $this->assertIsArray($puzzle['difficulty']);
        $this->assertSame(1, $puzzle['solves']['solo']['count'] ?? null);
    }

    /**
     * A member without history on a puzzle gets the statistical prediction
     * when there is enough data, and an all-null object when there is not -
     * the object is present either way, null means "not entitled" only.
     */
    public function testMemberWithoutHistoryGetsPredictionObject(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // nothing to predict from for a 9000-piece puzzle: all null, not personalized
        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '9000', 'pieces_max' => '9000']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['predicted_seconds' => null, 'range_low_seconds' => null, 'range_high_seconds' => null, 'is_personalized' => false, 'personal_solve_count' => null, 'predicted_attempt_number' => null, 'last_time_seconds' => null],
            $this->decode($browser)['puzzles'][0]['prediction'],
        );
    }

    public function testSolvesAreSplitByDiscipline(): void
    {
        $browser = $this->createApiClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        // three solo solves of PUZZLE_500_02: 2200 (20 days ago), 1900 (15), 1700 (10)
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();

        $rawSolves = $this->firstRawCard($browser)['solves'];
        $this->assertIsArray($rawSolves);
        $this->assertSame(['solo', 'duo', 'team'], array_keys($rawSolves));
        $this->assertSame(['count', 'best_time_seconds', 'last_time_seconds', 'first_solved_at', 'last_solved_at'], $this->keys($rawSolves['solo']));

        $solves = $this->decode($browser)['puzzles'][0]['solves'];
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
        $this->assertSame(['count' => 0, 'best_time_seconds' => null, 'last_time_seconds' => null, 'first_solved_at' => null, 'last_solved_at' => null], $solves['duo']);
        $this->assertSame(['count' => 0, 'best_time_seconds' => null, 'last_time_seconds' => null, 'first_solved_at' => null, 'last_solved_at' => null], $solves['team']);

        // a duo solve of PUZZLE_1000_01 (team-001 with PLAYER_PRIVATE, 3600 s) is a duo, never merged into solo
        $browser->request('GET', self::ENDPOINT, ['query' => 'RB-1000-001']);
        $this->assertResponseIsSuccessful();
        $solves = $this->decode($browser)['puzzles'][0]['solves'];
        $this->assertNotNull($solves);

        $this->assertSame(0, $solves['solo']['count']);
        $this->assertSame(1, $solves['duo']['count']);
        $this->assertSame(3600, $solves['duo']['best_time_seconds']);
        $this->assertSame(0, $solves['team']['count']);

        // a puzzle never touched: zeros, the object is still there
        $browser->request('GET', self::ENDPOINT, ['pieces_min' => '9000', 'pieces_max' => '9000']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->decode($browser)['puzzles'][0]['solves']['solo']['count'] ?? null);
    }

    /**
     * The query cost is fixed per request and does not grow with the page size
     * (docs/features/api/v1-expansion-plan.md §4, N3). Measured (2026-08-19):
     * authentication costs 1 query for a PAT, 3 for an OAuth2 token (access
     * token, player, consent usage); then count + search (2), statistics (1),
     * the owner's profile (1), solves (1), and for a member difficulty (1) +
     * predictions (3, or 4 when the page holds a puzzle the owner has not
     * solved yet - GetPlayerPredictions skips the statistical query otherwise).
     */
    public function testQueryBudgets(): void
    {
        $browser = $this->createApiClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);

        $this->authenticateClientCredentials($browser);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'client_credentials token, limit=100');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 7, 'non-member PAT, limit=100');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'non-member authorization-code token, limit=100');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 12, 'member PAT, limit=100');

        // Same member, two page sizes of the same listing: identical cost. Sorted
        // least-solved first, so that both pages hold puzzles the member has not
        // solved (the complete prediction path) - the comparison is then about
        // the page size only, not about which puzzles happen to be on the page.
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['sort' => 'least-solved', 'limit' => 5]);
        $this->assertResponseIsSuccessful();
        $memberAtFive = $this->queryCount($browser);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['sort' => 'least-solved', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertSame($memberAtFive, $this->queryCount($browser), 'A member pays the same number of queries for 100 items as for 5');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token, limit=100');

        // the barcode path: same rules, no count query
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 12, 'member authorization-code token, ean lookup');
    }

    public function testRateLimitReturnsTooManyRequestsWithRetryAfter(): void
    {
        $browser = $this->createApiClient();
        // PLAYER_ADMIN is used by no other search test, so the 60-request budget is all this test's
        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);

        for ($i = 1; $i <= 60; $i++) {
            $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
            $this->assertResponseIsSuccessful("request {$i} of 60 must still be accepted");
        }

        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $retryAfter = $browser->getResponse()->headers->get('Retry-After');
        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        // a 422 before the limiter is not counted, a different owner is not affected
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::ENDPOINT, ['ean' => '4005556123456']);
        $this->assertResponseIsSuccessful();
    }

    public function testOpenApiDocumentsEveryParameter(): void
    {
        $browser = $this->createApiClient();

        $parameters = $this->assertOpenApiHasParameters($browser, self::ENDPOINT, self::PARAMETER_NAMES);

        $this->assertSame(['most-solved', 'least-solved', 'a-z', 'z-a', 'easiest', 'hardest'], $parameters['sort']['schema']['enum'] ?? null);
        $this->assertSame('most-solved', $parameters['sort']['schema']['default'] ?? null);
        /** @var array{items?: array{enum?: list<string>}} $difficultySchema */
        $difficultySchema = $parameters['difficulty']['schema'];
        $this->assertSame(['very_easy', 'easy', 'average', 'challenging', 'hard', 'very_hard'], $difficultySchema['items']['enum'] ?? null);
        $this->assertSame('form', $parameters['difficulty']['style']);
        $this->assertFalse($parameters['difficulty']['explode']);
        $this->assertSame('integer', $parameters['limit']['schema']['type'] ?? null);
        $this->assertSame(100, $parameters['limit']['schema']['maximum'] ?? null);
        $this->assertSame(20, $parameters['limit']['schema']['default'] ?? null);
        $this->assertSame(500, $parameters['page']['schema']['maximum'] ?? null);
        $this->assertSame('^\d{8,14}$', $parameters['ean']['schema']['pattern'] ?? null);
        $this->assertSame('uuid', $parameters['manufacturer']['schema']['format'] ?? null);
        $this->assertStringContainsString('members-only', $parameters['sort']['description'] ?? '');

        $document = $this->openApiDocument($browser);
        /** @var array<string, array{get: array{tags: list<string>, summary: string, responses: array<int|string, mixed>}}> $paths */
        $paths = $document['paths'];
        $pathItem = $paths[self::ENDPOINT];
        $this->assertSame(['Puzzles'], $pathItem['get']['tags']);
        $this->assertNotSame('', $pathItem['get']['summary']);
        $this->assertArrayHasKey('429', $pathItem['get']['responses']);
        $this->assertArrayHasKey('422', $pathItem['get']['responses']);
    }

    private function createApiClient(): KernelBrowser
    {
        $browser = self::createClient();

        // The limiter store is not rolled back between tests or runs (filesystem
        // cache) - start every test with a full budget for every owner it uses.
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = $container->get('limiter.api_puzzle_search');

        foreach (self::LIMITER_KEYS as $key) {
            $limiter->create($key)->reset();
        }

        return $browser;
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

    private function assertUnprocessableWithViolationOn(KernelBrowser $browser, string $propertyPath): void
    {
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');

        $response = $this->decodeProblem($browser);
        $this->assertArrayHasKey('violations', $response, 'A 422 must carry violations');

        $paths = array_column($response['violations'], 'propertyPath');
        $this->assertContains($propertyPath, $paths, sprintf('Expected a violation on "%s", got: %s', $propertyPath, json_encode($response['violations'])));
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
     * The successful list response.
     *
     * @return ListResponse
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var ListResponse $decoded */
        $decoded = $this->decodeJson($browser);

        return $decoded;
    }

    /**
     * An error response (application/problem+json).
     *
     * @return Problem
     */
    private function decodeProblem(KernelBrowser $browser): array
    {
        /** @var Problem $decoded */
        $decoded = $this->decodeJson($browser);

        return $decoded;
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

    /**
     * The first card of the response as raw decoded JSON - for asserting field
     * names and their order, which the typed decode() cannot (phpstan folds a
     * typed shape's key list to a constant).
     *
     * @return array<string, mixed>
     */
    private function firstRawCard(KernelBrowser $browser): array
    {
        $puzzles = $this->decodeJson($browser)['puzzles'] ?? null;
        $this->assertIsArray($puzzles);
        $this->assertArrayHasKey(0, $puzzles);

        /** @var array<string, mixed> $card */
        $card = $puzzles[0];

        return $card;
    }

    /**
     * Keys of a decoded JSON object, for asserting field names and their order.
     *
     * @return list<int|string>
     */
    private function keys(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_keys($value);
    }

    /**
     * @param ListResponse $response
     *
     * @return list<string>
     */
    private function ids(array $response): array
    {
        return array_map(static fn (array $puzzle): string => $puzzle['id'], $response['puzzles']);
    }

    /**
     * @param ListResponse $response
     *
     * @return Card
     */
    private function puzzle(array $response, string $puzzleId): array
    {
        foreach ($response['puzzles'] as $puzzle) {
            if ($puzzle['id'] === $puzzleId) {
                return $puzzle;
            }
        }

        $this->fail(sprintf('Puzzle %s is not in the response', $puzzleId));
    }
}
