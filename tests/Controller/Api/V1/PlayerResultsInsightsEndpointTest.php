<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET /api/v1/players/{playerId}/results - every result carries the solved
 * puzzle's statistics (public) and difficulty (the *token owner* is a member,
 * never a machine token), at a fixed query cost whatever the list size. The
 * existing fields and the private-profile short-circuit are untouched
 * (PlayerResultsEndpointTest).
 *
 * Fixtures (.claude/fixtures.md): PLAYER_REGULAR (not a member) has 17 solo
 * results, among them TIME_08 - PUZZLE_500_02 in 1700 s; PLAYER_WITH_STRIPE is
 * a member with 11 solo results; PLAYER_PRIVATE has a private profile.
 *
 * @phpstan-type StatisticsGroup array{count: int, fastest_seconds: null|int, average_seconds: null|int, slowest_seconds: null|int, median_seconds: null|int}
 * @phpstan-type Result array{
 *     time_id: string,
 *     puzzle_id: string,
 *     puzzle_name: string,
 *     manufacturer_name: string,
 *     pieces_count: int,
 *     time_seconds: null|int,
 *     finished_at: null|string,
 *     first_attempt: bool,
 *     puzzle_image: null|string,
 *     comment: null|string,
 *     statistics: array{solved_times: int, solo: StatisticsGroup, duo: StatisticsGroup, team: StatisticsGroup},
 *     difficulty: null|array{score: null|float, level: null|string, confidence: string, sample_size: int},
 * }
 * @phpstan-type ListResponse array{player_id: string, type: string, count: int, results: list<Result>}
 */
final class PlayerResultsInsightsEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    /** @var list<string> the result fields before the insight objects were added - must stay byte-identical */
    private const array ORIGINAL_RESULT_KEYS = ['time_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'time_seconds', 'finished_at', 'first_attempt', 'puzzle_image', 'comment'];

    public function testExistingFieldsAreUnchangedAndTheInsightObjectsAreAppended(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();

        $raw = $this->decodeJson($browser);
        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $raw['player_id'] ?? null);
        $this->assertSame('solo', $raw['type'] ?? null);
        $this->assertSame(17, $raw['count'] ?? null);
        $rawResults = $raw['results'] ?? null;
        $this->assertIsArray($rawResults);
        $this->assertCount(17, $rawResults);

        foreach ($rawResults as $rawResult) {
            $this->assertSame([...self::ORIGINAL_RESULT_KEYS, 'statistics', 'difficulty'], $this->keys($rawResult));
            $this->assertIsArray($rawResult);
            $this->assertSame(['solved_times', 'solo', 'duo', 'team'], $this->keys($rawResult['statistics']));
        }

        // the values the endpoint returned before this change (TIME_08: the 1700 s solve of PUZZLE_500_02)
        $result = $this->resultByTimeId($this->decode($browser), PuzzleSolvingTimeFixture::TIME_08);
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $result['puzzle_id']);
        $this->assertSame('Puzzle 2', $result['puzzle_name']);
        $this->assertSame('Ravensburger', $result['manufacturer_name']);
        $this->assertSame(500, $result['pieces_count']);
        $this->assertSame(1700, $result['time_seconds']);
        $this->assertIsString($result['finished_at']);
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $result['finished_at']));
        $this->assertFalse($result['first_attempt']);
        $this->assertNull($result['puzzle_image']);
        $this->assertNull($result['comment']);
    }

    /**
     * Difficulty follows the token owner's membership, whoever the listed player is.
     */
    public function testDifficultyFollowsTheTokenOwnersMembership(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);

        // a member looking at a non-member's results
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        foreach ($response['results'] as $result) {
            $this->assertIsArray($result['difficulty']);
        }

        $this->assertSame(['score', 'level', 'confidence', 'sample_size'], $this->keys($this->rawResult($browser, PuzzleSolvingTimeFixture::TIME_08)['difficulty']));
        $result = $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_08);
        $this->assertSame(['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20], $result['difficulty']);
        // statistics of PUZZLE_500_02: ten solo solves, split by discipline
        $this->assertSame(10, $result['statistics']['solved_times']);
        $this->assertSame(10, $result['statistics']['solo']['count']);
        $this->assertSame(1350, $result['statistics']['solo']['fastest_seconds']);
        $this->assertSame(0, $result['statistics']['duo']['count']);

        // a non-member looking at a member's results
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(11, $response['count']);

        foreach ($response['results'] as $result) {
            $this->assertNull($result['difficulty']);
            $this->assertGreaterThanOrEqual(0, $result['statistics']['solved_times']);
        }

        // the duo list of PLAYER_REGULAR: the duo solve of PUZZLE_1000_01 (3600 s)
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR), ['type' => 'duo']);
        $this->assertResponseIsSuccessful();
        $duo = $this->resultByTimeId($this->decode($browser), PuzzleSolvingTimeFixture::TIME_12);
        $this->assertSame(['count' => 1, 'fastest_seconds' => 3600, 'average_seconds' => 3600, 'slowest_seconds' => 3600, 'median_seconds' => 3600], $duo['statistics']['duo']);
        $this->assertNull($duo['difficulty']);
    }

    public function testClientCredentialsTokenGetsStatisticsButNoDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticateClientCredentials($browser);

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(17, $response['count']);

        foreach ($response['results'] as $result) {
            $this->assertNull($result['difficulty']);
        }

        $this->assertSame(10, $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_08)['statistics']['solved_times']);
    }

    public function testPrivateProfileStaysZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();

        $response = $this->decode($browser);
        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $response['player_id']);
        $this->assertSame(0, $response['count']);
        $this->assertSame([], $response['results']);
        // authentication (3) + the profile lookup - nothing else
        $this->assertQueryCountAtMost($browser, 4, 'private profile short-circuit');
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_01, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_08)['puzzle_image']);
        $this->assertSame('puzzles/test/other.jpg', $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_01)['puzzle_image']);
    }

    /**
     * Measured (2026-08-19): authentication 3 queries (OAuth2; a
     * client_credentials token 1-2), the listed player's profile 1, the
     * existence check 1, the results 1; then statistics 1 and, for a player
     * token, the owner's profile 1 and for a member difficulty 1.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticateClientCredentials($browser);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'client_credentials token (statistics)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'non-member authorization-code token (statistics, profile)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR));
        $this->assertResponseIsSuccessful();
        $this->assertSame(17, $this->decode($browser)['count']);
        $this->assertQueryCountAtMost($browser, 9, 'member authorization-code token (statistics, profile, difficulty)');
        $atSeventeen = $this->queryCount($browser);

        // the same member token on a shorter list: the same cost
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertSame(11, $this->decode($browser)['count']);
        $this->assertSame($atSeventeen, $this->queryCount($browser), 'The same number of queries for 11 results as for 17');
    }

    private function path(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/results';
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
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function seedDifficulty(KernelBrowser $browser, string $puzzleId, float $score, MetricConfidence $confidence): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $difficulty = $entityManager->find(PuzzleDifficulty::class, $puzzleId) ?? new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, 20, new DateTimeImmutable());

        $entityManager->persist($difficulty);
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

    private function entityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * @return ListResponse
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var ListResponse $decoded */
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
     * One result of the response as raw decoded JSON - for asserting field
     * names and their order, which the typed decode() cannot.
     *
     * @return array<string, mixed>
     */
    private function rawResult(KernelBrowser $browser, string $timeId): array
    {
        $results = $this->decodeJson($browser)['results'] ?? null;
        $this->assertIsArray($results);

        foreach ($results as $result) {
            $this->assertIsArray($result);

            if (($result['time_id'] ?? null) === $timeId) {
                /** @var array<string, mixed> $result */
                return $result;
            }
        }

        $this->fail(sprintf('Time %s is not in the response', $timeId));
    }

    /**
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
     * @return Result
     */
    private function resultByTimeId(array $response, string $timeId): array
    {
        foreach ($response['results'] as $result) {
            if ($result['time_id'] === $timeId) {
                return $result;
            }
        }

        $this->fail(sprintf('Time %s is not in the response', $timeId));
    }
}
