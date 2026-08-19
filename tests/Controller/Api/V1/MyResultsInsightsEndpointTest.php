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
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET /api/v1/me/results - every result carries the solved puzzle's statistics
 * (public) and difficulty (token owner member), at a fixed query cost whatever
 * the list size. The existing fields are untouched (MyResultsEndpointTest).
 *
 * Fixtures (.claude/fixtures.md): PLAYER_REGULAR (not a member) has 17 solo
 * results, among them TIME_08 - PUZZLE_500_02 in 1700 s - and two duo results;
 * PLAYER_WITH_STRIPE and PLAYER_ADMIN are members.
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
final class MyResultsInsightsEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/me/results';

    /** @var list<string> the result fields before the insight objects were added - must stay byte-identical */
    private const array ORIGINAL_RESULT_KEYS = ['time_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'time_seconds', 'finished_at', 'first_attempt', 'puzzle_image', 'comment'];

    public function testExistingFieldsAreUnchangedAndTheInsightObjectsAreAppended(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT);
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
            $rawStatistics = $rawResult['statistics'];
            $this->assertIsArray($rawStatistics);
            $this->assertSame(['solved_times', 'solo', 'duo', 'team'], array_keys($rawStatistics));
            $this->assertSame(['count', 'fastest_seconds', 'average_seconds', 'slowest_seconds', 'median_seconds'], $this->keys($rawStatistics['solo']));
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

    public function testNonMemberGetsStatisticsButNoDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $result = $this->resultByTimeId($this->decode($browser), PuzzleSolvingTimeFixture::TIME_08);

        // community statistics of PUZZLE_500_02, public, split by discipline: ten solo solves
        $this->assertSame(10, $result['statistics']['solved_times']);
        $this->assertSame(10, $result['statistics']['solo']['count']);
        $this->assertSame(1350, $result['statistics']['solo']['fastest_seconds']);
        $this->assertSame(2500, $result['statistics']['solo']['slowest_seconds']);
        $this->assertSame(0, $result['statistics']['duo']['count']);
        $this->assertNull($result['difficulty']);

        // the duo list: the duo solve of PUZZLE_1000_01 (3600 s) - its statistics split the same way
        $browser->request('GET', self::ENDPOINT, ['type' => 'duo']);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame('duo', $response['type']);
        $duo = $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_12);
        $this->assertSame(PuzzleFixture::PUZZLE_1000_01, $duo['puzzle_id']);
        $this->assertSame(['count' => 1, 'fastest_seconds' => 3600, 'average_seconds' => 3600, 'slowest_seconds' => 3600, 'median_seconds' => 3600], $duo['statistics']['duo']);
        $this->assertGreaterThan(0, $duo['statistics']['solo']['count']);
        $this->assertNull($duo['difficulty']);
    }

    public function testMemberGetsDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(11, $response['count']);

        foreach ($response['results'] as $result) {
            $this->assertIsArray($result['difficulty']);
        }

        $this->assertSame(['score', 'level', 'confidence', 'sample_size'], $this->keys($this->rawResult($browser, PuzzleSolvingTimeFixture::TIME_05)['difficulty']));
        $this->assertSame(
            ['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20],
            $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_05)['difficulty'],
        );

        // an authorization-code token of the same member: the same
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1.18, $this->resultByTimeId($this->decode($browser), PuzzleSolvingTimeFixture::TIME_05)['difficulty']['score'] ?? null);

        // a non-member's authorization-code token: null
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->resultByTimeId($this->decode($browser), PuzzleSolvingTimeFixture::TIME_01)['difficulty']);
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_01, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_08)['puzzle_image']);
        $this->assertSame('puzzles/test/other.jpg', $this->resultByTimeId($response, PuzzleSolvingTimeFixture::TIME_01)['puzzle_image']);
    }

    /**
     * Measured (2026-08-19): authentication 1 query (PAT) / 3 (OAuth2), the
     * player existence check 1, the results 1 (+1 team-players lookup for duo
     * and team lists); then statistics 1, the owner's profile 1 and, for a
     * member, difficulty 1. An empty list adds nothing.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'non-member PAT (statistics, profile)');

        // the team list of PLAYER_REGULAR is empty: no batch query at all
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT, ['type' => 'team']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->decode($browser)['count']);
        $this->assertQueryCountAtMost($browser, 4, 'empty list');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'member PAT (statistics, profile, difficulty)');
        $memberAtEleven = $this->queryCount($browser);

        // another member with a different number of results: the same cost
        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $adminCount = $this->decode($browser)['count'];
        $this->assertGreaterThan(0, $adminCount);
        $this->assertNotSame(11, $adminCount);
        $this->assertSame($memberAtEleven, $this->queryCount($browser), 'A member pays the same number of queries whatever the length of the list');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::ENDPOINT);
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'member authorization-code token');
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
