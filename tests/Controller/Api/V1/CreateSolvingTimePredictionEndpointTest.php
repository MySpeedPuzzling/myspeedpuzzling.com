<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * POST /api/v1/me/solving-times answers with the prediction that applied *before*
 * the solve (what the website's added-time recap shows) and with the parsed
 * time_seconds. The gates are the recap page's: solo time, time present, the token
 * owner a member who has not opted out - plus the API's own rule that reading a
 * prediction takes PAT or results:read, the write scope alone does not.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE is a member with one solo solve
 * of PUZZLE_500_01 (2100 s) and a 500-piece baseline; PLAYER_REGULAR is not a member
 * and has three solo solves of PUZZLE_500_02. Nobody has solved PUZZLE_500_04.
 *
 * Query budgets: the write path is not free - the created time's PuzzleSolved
 * event runs the statistics / intelligence recalculations, the wishlist removal
 * and the notifications synchronously, and how much it does depends on the data
 * (repeat solves recompute improvement ratios, subscribers get notification rows).
 * The WRITE_PATH_* constants are the request-only counts of each scenario measured
 * 2026-08-19 with the prediction switched off (PAT authentication included), so the
 * ceilings pin only what this feature adds: owner profile 1 + prediction <= 5 for an
 * eligible request, owner profile 1 at most otherwise, nothing for a group time.
 * Re-measured after the XP/badges branch: AddPuzzleSolvingTimeHandler now dispatches
 * RecalculateBadgesForPlayer + AwardXpForSolvingTime (async — the handlers do not run
 * in-request), and each dispatch inside the open transaction costs a SAVEPOINT +
 * RELEASE pair from the doctrine_transaction middleware => every write path is +4.
 *
 * @phpstan-type Prediction array{predicted_seconds: null|int, range_low_seconds: null|int, range_high_seconds: null|int, is_personalized: bool, personal_solve_count: null|int, predicted_attempt_number: null|int, last_time_seconds: null|int}
 * @phpstan-type SolvingTimeResponse array{time_id: string, puzzle_id: string, time_seconds: null|int, finished_at: null|string, first_attempt: bool, unboxed: bool, comment: null|string, round_id: null|string}
 */
final class CreateSolvingTimePredictionEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/me/solving-times';

    /** PLAYER_WITH_STRIPE, solo, PUZZLE_500_01 (one earlier solve - the create recomputes improvement ratios) */
    private const int WRITE_PATH_MEMBER_SOLO_500_01 = 33;

    /** PLAYER_WITH_STRIPE, solo, PUZZLE_500_04 (first solve of the puzzle) */
    private const int WRITE_PATH_MEMBER_SOLO_500_04 = 32;

    /** PLAYER_REGULAR, solo, PUZZLE_500_02 (three earlier solves, a subscriber to notify) */
    private const int WRITE_PATH_NON_MEMBER_SOLO_500_02 = 39;

    /** PLAYER_WITH_STRIPE, duo with a guest, PUZZLE_500_01 */
    private const int WRITE_PATH_MEMBER_GROUP_500_01 = 25;

    /** ApiTokenOwner::profile(), memoised - once per request at most */
    private const int OWNER_PROFILE_QUERIES = 1;

    /** GetPlayerPrediction::forPuzzle - personal: solves, pieces, player ratio, global ratio (+ the "all" bucket); statistical: solves + 1 */
    private const int PREDICTION_QUERIES_MAX = 5;

    /**
     * The member has one earlier solo solve of the puzzle: the prediction is the
     * personalised one that applied before this time - the new time is excluded,
     * so personal_solve_count is the count *before* it and last_time_seconds the
     * earlier solve, not the one just posted.
     */
    public function testMemberSoloTimeWithHistoryGetsThePredictionThatAppliedBefore(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_01, 'time' => '25:10']);

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost(
            $browser,
            self::WRITE_PATH_MEMBER_SOLO_500_01 + self::OWNER_PROFILE_QUERIES + self::PREDICTION_QUERIES_MAX,
            'Member solo create with a personal prediction (PAT)',
        );
        $response = $this->decode($browser);

        $this->assertSame(1510, $response['time_seconds']);
        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);

        $prediction = $this->prediction($browser);
        $this->assertNotNull($prediction);
        $this->assertTrue($prediction['is_personalized']);
        $this->assertSame(1, $prediction['personal_solve_count']);
        $this->assertSame(2, $prediction['predicted_attempt_number']);
        $this->assertSame(2100, $prediction['last_time_seconds']);
        $this->assertIsInt($prediction['predicted_seconds']);
        $this->assertGreaterThan(0, $prediction['predicted_seconds']);
        $this->assertIsInt($prediction['range_low_seconds']);
        $this->assertIsInt($prediction['range_high_seconds']);
        $this->assertLessThanOrEqual($prediction['predicted_seconds'], $prediction['range_low_seconds']);
        $this->assertGreaterThanOrEqual($prediction['predicted_seconds'], $prediction['range_high_seconds']);

        // time_seconds is what the handler stored - same parser, same number
        $this->assertSame(1510, $this->storedSeconds($browser, $response['time_id']));
    }

    /**
     * No earlier solve of this puzzle, but a 500-piece baseline and a scored puzzle:
     * the statistical (baseline x difficulty) prediction applied before the solve.
     * The create's own synchronous recalculation rewrites the puzzle's difficulty
     * row from the actual solves, so the puzzle is made scorable by seeding four
     * other players' first attempts (5 indices with the new one = scored, "low").
     */
    public function testMemberWithoutHistoryGetsTheStatisticalPrediction(): void
    {
        $browser = self::createClient();

        foreach (
            [
            PlayerFixture::PLAYER_REGULAR => 1900,
            PlayerFixture::PLAYER_PRIVATE => 1650,
            PlayerFixture::PLAYER_ADMIN => 2050,
            PlayerFixture::PLAYER_WITH_FAVORITES => 2950,
            ] as $playerId => $seconds
        ) {
            $this->seedSoloSolve($browser, $playerId, PuzzleFixture::PUZZLE_500_04, $seconds);
        }

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_04, 'time' => '1:23:45']);

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost(
            $browser,
            self::WRITE_PATH_MEMBER_SOLO_500_04 + self::OWNER_PROFILE_QUERIES + self::PREDICTION_QUERIES_MAX,
            'Member solo create with a statistical prediction (PAT)',
        );
        $response = $this->decode($browser);

        $this->assertSame(5025, $response['time_seconds']);

        $prediction = $this->prediction($browser);
        $this->assertNotNull($prediction);
        $this->assertFalse($prediction['is_personalized']);
        $this->assertNull($prediction['personal_solve_count']);
        $this->assertNull($prediction['predicted_attempt_number']);
        $this->assertNull($prediction['last_time_seconds']);
        $this->assertIsInt($prediction['predicted_seconds']);
        $this->assertGreaterThan(0, $prediction['predicted_seconds']);
        $this->assertIsInt($prediction['range_low_seconds']);
        $this->assertIsInt($prediction['range_high_seconds']);
    }

    /**
     * Members-only, like the recap page: a non-member gets null even with three
     * earlier solves of the puzzle to predict from. time_seconds is filled anyway.
     */
    public function testNonMemberGetsNoPrediction(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_02, 'time' => '25:10']);

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost(
            $browser,
            self::WRITE_PATH_NON_MEMBER_SOLO_500_02 + self::OWNER_PROFILE_QUERIES,
            'Non-member solo create (PAT)',
        );
        $response = $this->decode($browser);

        $this->assertNull($this->prediction($browser));
        $this->assertSame(1510, $response['time_seconds']);
    }

    /**
     * A group time is a duo/team discipline - predictions are solo-only on the
     * website (the recap page shows none), so the response carries null.
     */
    public function testGroupTimeGetsNoPrediction(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $this->post($browser, [
            'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
            'time' => '20:00',
            'group_players' => ['Guest Puzzler'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, self::WRITE_PATH_MEMBER_GROUP_500_01, 'Member group create (PAT) - no profile, no prediction query');
        $response = $this->decode($browser);

        $this->assertNull($this->prediction($browser));
        $this->assertSame(1200, $response['time_seconds']);
    }

    public function testOptedOutMemberGetsNoPrediction(): void
    {
        $browser = self::createClient();
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_01, 'time' => '25:10']);

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost(
            $browser,
            self::WRITE_PATH_MEMBER_SOLO_500_01 + self::OWNER_PROFILE_QUERIES,
            'Opted-out member solo create (PAT)',
        );
        $response = $this->decode($browser);

        $this->assertNull($this->prediction($browser));
        $this->assertSame(1510, $response['time_seconds']);
    }

    /**
     * The write scope lets a token create the time, not read the owner's insights:
     * like every other prediction object it takes PAT or results:read.
     */
    public function testOAuth2TokenWithWriteScopeOnlyGetsNoPrediction(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['solving-times:write']);

        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_01, 'time' => '25:10']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($this->prediction($browser));
        $this->assertSame(1510, $response['time_seconds']);
    }

    public function testOAuth2TokenWithWriteAndResultsReadScopesGetsThePrediction(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['solving-times:write', 'results:read']);

        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_01, 'time' => '25:10']);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $prediction = $this->prediction($browser);
        $this->assertNotNull($prediction);
        $this->assertTrue($prediction['is_personalized']);
        $this->assertSame(1, $prediction['personal_solve_count']);
        $this->assertSame(2100, $prediction['last_time_seconds']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideTimes(): iterable
    {
        yield 'HH:MM:SS' => ['1:23:45', 5025];
        yield 'MM:SS' => ['25:10', 1510];
        yield 'zero-padded hours' => ['00:25:00', 1500];
    }

    #[DataProvider('provideTimes')]
    public function testTimeSecondsIsTheParsedTime(string $time, int $expectedSeconds): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->post($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_1000_01, 'time' => $time]);

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame($expectedSeconds, $response['time_seconds']);
        $this->assertSame($expectedSeconds, $this->storedSeconds($browser, $response['time_id']));
    }

    /**
     * PUT shares the response class; the field exists there too, always null - the
     * "before" prediction is a property of creating a time.
     */
    public function testUpdateResponseKeepsPredictionNull(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'PUT',
            self::ENDPOINT . '/' . PuzzleSolvingTimeFixture::TIME_05,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['time' => '00:34:00', 'comment' => 'Updated via API']),
        );

        $this->assertResponseIsSuccessful();

        $this->assertNull($this->prediction($browser));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(KernelBrowser $browser, array $payload): void
    {
        $browser->request(
            'POST',
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload, JSON_THROW_ON_ERROR),
        );
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
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            $playerId,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function seedSoloSolve(KernelBrowser $browser, string $playerId, string $puzzleId, int $seconds): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $solvedAt = new DateTimeImmutable('-30 days');

        $entityManager->persist(new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $solvedAt,
            verified: true,
            team: null,
            finishedAt: $solvedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: true,
            unboxed: false,
        ));
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

    private function storedSeconds(KernelBrowser $browser, string $timeId): int
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var Connection $database */
        $database = $container->get(Connection::class);

        /** @var int|string|false $seconds */
        $seconds = $database->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        $this->assertNotFalse($seconds);

        return (int) $seconds;
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
     * The prediction object of the response - the key must always be present,
     * null when the token is not entitled to one.
     *
     * @return null|Prediction
     */
    private function prediction(KernelBrowser $browser): null|array
    {
        $response = $this->decodeRaw($browser);
        $this->assertArrayHasKey('prediction', $response);

        if ($response['prediction'] === null) {
            return null;
        }

        /** @var Prediction $prediction */
        $prediction = $response['prediction'];

        return $prediction;
    }

    /**
     * @return SolvingTimeResponse
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var SolvingTimeResponse $decoded */
        $decoded = $this->decodeRaw($browser);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRaw(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
