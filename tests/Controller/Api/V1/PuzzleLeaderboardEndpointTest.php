<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/puzzles/{puzzleId}/leaderboard - the puzzle page's leaderboard
 * as an API: best time per player / exact pair-team, the website's filters
 * and privacy rules, relative to the token owner.
 *
 * Fixtures (.claude/fixtures.md): PUZZLE_500_01 is solved solo by all five
 * players - PLAYER_ADMIN (cz) best 1200 s, PLAYER_REGULAR (cz) best 1750 s,
 * PLAYER_WITH_STRIPE (gb) 2100 s, PLAYER_WITH_FAVORITES (de) 3000 s and the
 * private PLAYER_PRIVATE (us). PLAYER_REGULAR & PLAYER_PRIVATE are a pair on
 * PUZZLE_1000_01 (TIME_12). PUZZLE_5000 has never been solved.
 *
 * @phpstan-type LeaderboardPlayer array{id: null|string, name: null|string, code: null|string, country: null|string, is_private: bool}
 * @phpstan-type Entry array{rank: int, time_id: string, time_seconds: int, finished_at: null|string, first_attempt: bool, team_id: null|string, team_name: null|string, players: list<LeaderboardPlayer>}
 * @phpstan-type Leaderboard array{puzzle_id: string, type: string, country: null|string, first_attempt_only: bool, count: int, total: int, page: int, limit: int, has_more: bool, entries: list<Entry>}
 * @phpstan-type Problem array{detail: string, violations?: list<array{propertyPath: string, message: string}>}
 */
final class PuzzleLeaderboardEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    private const string OPENAPI_PATH = '/api/v1/puzzles/{puzzleId}/leaderboard';

    /** @var list<string> */
    private const array PARAMETER_NAMES = ['type', 'country', 'first_attempt_only', 'page', 'limit'];

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSoloLeaderboardIsBestTimePerPlayerFastestFirst(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);
        $this->assertSame('solo', $response['type']);
        $this->assertNull($response['country']);
        $this->assertFalse($response['first_attempt_only']);
        $this->assertSame(1, $response['page']);
        $this->assertSame(20, $response['limit']);
        $this->assertFalse($response['has_more']);
        $this->assertSame($response['total'], $response['count']);

        $playerIds = $this->playerIds($response);
        $this->assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES],
            $playerIds,
            'One row per player, the private player left out',
        );

        $this->assertSame([1200, 1750, 2100, 3000], array_column($response['entries'], 'time_seconds'));
        $this->assertSame([1, 2, 3, 4], array_column($response['entries'], 'rank'));

        $first = $response['entries'][0];
        $this->assertSame(['id' => PlayerFixture::PLAYER_ADMIN, 'name' => 'Admin User', 'code' => $first['players'][0]['code'], 'country' => 'cz', 'is_private' => false], $first['players'][0]);
        $this->assertNull($first['team_id']);
    }

    public function testPrivatePlayerSeesTheirOwnRow(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01));

        $this->assertResponseIsSuccessful();
        $this->assertContains(PlayerFixture::PLAYER_PRIVATE, $this->playerIds($this->decode($browser)));
    }

    public function testClientCredentialsTokenGetsThePublicLeaderboard(): void
    {
        $browser = self::createClient();
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            ['profile:read'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01));

        $this->assertResponseIsSuccessful();
        $this->assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->playerIds($this->decode($browser)));
    }

    public function testCountryFilterIsCaseInsensitive(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['country' => 'CZ']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame('cz', $response['country']);
        $this->assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR], $this->playerIds($response));
        $this->assertSame([1, 2], array_column($response['entries'], 'rank'), 'Rank is the position in the filtered list');
    }

    public function testFirstAttemptOnlyRanksFirstTries(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['first_attempt_only' => 'true']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertTrue($response['first_attempt_only']);
        $this->assertGreaterThan(0, $response['count']);

        foreach ($response['entries'] as $entry) {
            $this->assertTrue($entry['first_attempt']);
        }

        $times = array_column($response['entries'], 'time_seconds');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function testDuoRowMasksThePrivateMember(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_1000_01), ['type' => 'duo']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame('duo', $response['type']);
        $this->assertSame(1, $response['total']);

        $pair = $response['entries'][0];
        $this->assertSame(3600, $pair['time_seconds']);
        $this->assertNotNull($pair['team_id']);
        $this->assertCount(2, $pair['players']);

        $visible = array_values(array_filter($pair['players'], static fn (array $player): bool => $player['is_private'] === false));
        $masked = array_values(array_filter($pair['players'], static fn (array $player): bool => $player['is_private'] === true));

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $visible[0]['id'] ?? null);
        $this->assertSame([['id' => null, 'name' => null, 'code' => null, 'country' => null, 'is_private' => true]], $masked);
    }

    public function testPaginationWalksTheSameList(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['limit' => 3]);
        $this->assertResponseIsSuccessful();
        $first = $this->decode($browser);

        $this->assertSame(3, $first['count']);
        $this->assertSame(4, $first['total']);
        $this->assertTrue($first['has_more']);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['limit' => 3, 'page' => 2]);
        $this->assertResponseIsSuccessful();
        $second = $this->decode($browser);

        $this->assertSame(1, $second['count']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(4, $second['entries'][0]['rank']);
        $this->assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $second['entries'][0]['players'][0]['id']);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['limit' => 3, 'page' => 3]);
        $this->assertResponseIsSuccessful();
        $past = $this->decode($browser);
        $this->assertSame(0, $past['count']);
        $this->assertSame(4, $past['total']);
    }

    public function testUnsolvedPuzzleHasAnEmptyLeaderboard(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_5000));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(0, $response['total']);
        $this->assertSame([], $response['entries']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'type unknown' => [['type' => 'relay'], 'type'];
        yield 'country not a code' => [['country' => 'Czechia'], 'country'];
        yield 'country unknown code' => [['country' => 'xx'], 'country'];
        yield 'first_attempt_only not a boolean' => [['first_attempt_only' => 'yes'], 'first_attempt_only'];
        yield 'page below 1' => [['page' => '0'], 'page'];
        yield 'page not an integer' => [['page' => 'two'], 'page'];
        yield 'limit below 1' => [['limit' => '0'], 'limit'];
        yield 'limit above 100' => [['limit' => '101'], 'limit'];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('invalidParameters')]
    public function testInvalidParameterIsRejected(array $parameters, string $expectedPropertyPath): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), $parameters);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertResponseHeaderSame('content-type', 'application/problem+json');

        $problem = $this->decodeProblem($browser);
        $this->assertArrayHasKey('violations', $problem, 'A 422 must carry violations');
        $this->assertContains($expectedPropertyPath, array_column($problem['violations'], 'propertyPath'));
    }

    public function testUnknownPuzzleIsNotFound(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::url('018d0000-0000-7000-8000-000000000000'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $browser->request('GET', self::url('not-a-uuid'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSecretCompetitionPuzzleIsNotFound(): void
    {
        $browser = self::createClient();

        $entityManager = $this->entityManager($browser);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        $this->assertNotNull($puzzle);
        $puzzle->hideUntil = TestClock::now()->modify('+1 day');
        $entityManager->flush();
        $entityManager->clear();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Two statements of the website's leaderboard (puzzle + solvers), whatever the page size.
     */
    public function testQueryBudget(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_500_01), ['limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'member PAT, solo, limit=100');

        $this->startCountingQueries($browser);
        $browser->request('GET', self::url(PuzzleFixture::PUZZLE_1000_01), ['type' => 'duo', 'limit' => 100]);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'member PAT, duo, limit=100');
    }

    public function testOpenApiDocumentsEveryParameter(): void
    {
        $browser = self::createClient();

        $parameters = $this->assertOpenApiHasParameters($browser, self::OPENAPI_PATH, self::PARAMETER_NAMES);

        $this->assertSame(['solo', 'duo', 'team'], $parameters['type']['schema']['enum'] ?? null);
        $this->assertSame('solo', $parameters['type']['schema']['default'] ?? null);
        $this->assertSame('boolean', $parameters['first_attempt_only']['schema']['type'] ?? null);
        $this->assertSame(100, $parameters['limit']['schema']['maximum'] ?? null);
        $this->assertSame(20, $parameters['limit']['schema']['default'] ?? null);

        $document = $this->openApiDocument($browser);
        /** @var array<string, array{get: array{tags: list<string>, summary: string, responses: array<int|string, mixed>}}> $paths */
        $paths = $document['paths'];
        $operation = $paths[self::OPENAPI_PATH]['get'];
        $this->assertSame(['Puzzles'], $operation['tags']);
        $this->assertNotSame('', $operation['summary']);
        $this->assertArrayHasKey('404', $operation['responses']);
        $this->assertArrayHasKey('422', $operation['responses']);
    }

    private static function url(string $puzzleId): string
    {
        return '/api/v1/puzzles/' . $puzzleId . '/leaderboard';
    }

    private function authenticatePat(KernelBrowser $browser, string $playerId): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));
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
     * @param Leaderboard $response
     * @return list<null|string>
     */
    private function playerIds(array $response): array
    {
        return array_map(static fn (array $entry): null|string => $entry['players'][0]['id'], $response['entries']);
    }

    /**
     * @return Leaderboard
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var Leaderboard $decoded */
        $decoded = $this->decodeJson($browser);

        return $decoded;
    }

    /**
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
}
