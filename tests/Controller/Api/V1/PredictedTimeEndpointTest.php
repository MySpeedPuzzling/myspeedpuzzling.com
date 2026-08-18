<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/puzzles/{puzzleId}/predicted-time - the API twin of the Puzzle
 * Insights block on the puzzle detail page, so the gates must match the website:
 * members-only, prediction also honours the owner's opt-out.
 *
 * Fixtures (see .claude/fixtures.md): PLAYER_WITH_STRIPE is a member with one solo
 * solve of PUZZLE_500_01 (2100 s) and a 500-piece baseline; PLAYER_REGULAR is not a
 * member. Difficulty rows are seeded per test - the intelligence recalculation is
 * batch, so nothing in the fixtures guarantees a scored puzzle_difficulty row.
 */
final class PredictedTimeEndpointTest extends WebTestCase
{
    public function testMemberWithHistoryGetsPersonalizedPredictionAndDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);
        $this->assertTrue($response['is_personalized']);
        $this->assertSame(1, $response['personal_solve_count']);
        $this->assertSame(2100, $response['last_time_seconds']);
        $this->assertIsInt($response['predicted_seconds']);
        $this->assertGreaterThan(0, $response['predicted_seconds']);
        $this->assertIsInt($response['range_low_seconds']);
        $this->assertIsInt($response['range_high_seconds']);
        $this->assertLessThanOrEqual($response['predicted_seconds'], $response['range_low_seconds']);
        $this->assertGreaterThanOrEqual($response['predicted_seconds'], $response['range_high_seconds']);

        $this->assertSame(1.0263, $response['difficulty_score']);
        $this->assertSame('average', $response['difficulty_level']);
        $this->assertSame('medium', $response['difficulty_confidence']);
    }

    /**
     * No solve of this puzzle yet, but a 500-piece baseline and a scored puzzle: the
     * statistical (baseline x difficulty) prediction is returned, flagged non-personal.
     */
    public function testMemberWithoutHistoryGetsStatisticalPrediction(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_04, 1.30, MetricConfidence::Low);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_04 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['is_personalized']);
        $this->assertNull($response['personal_solve_count']);
        $this->assertNull($response['last_time_seconds']);
        $this->assertIsInt($response['predicted_seconds']);
        $this->assertGreaterThan(0, $response['predicted_seconds']);
        $this->assertSame(1.3, $response['difficulty_score']);
        $this->assertSame('hard', $response['difficulty_level']);
        $this->assertSame('low', $response['difficulty_confidence']);
    }

    /**
     * Nothing to predict from (no solve, no baseline for 5000 pieces) - the difficulty
     * is still returned, exactly as a member sees it on the puzzle page.
     */
    public function testMemberWithoutAnyPredictionStillGetsDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_5000, 0.70, MetricConfidence::High);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_5000 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($response['predicted_seconds']);
        $this->assertNull($response['range_low_seconds']);
        $this->assertNull($response['range_high_seconds']);
        $this->assertFalse($response['is_personalized']);
        $this->assertSame(0.7, $response['difficulty_score']);
        $this->assertSame('very_easy', $response['difficulty_level']);
        $this->assertSame('high', $response['difficulty_confidence']);
    }

    /**
     * Members-only, like the website: a non-member gets no prediction AND no difficulty,
     * even though a scored difficulty row exists for the puzzle.
     */
    public function testNonMemberGetsNothing(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.0, MetricConfidence::High);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        // PLAYER_REGULAR has three solo solves of PUZZLE_500_02 - plenty to predict from
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_02 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $response['puzzle_id']);
        $this->assertNull($response['predicted_seconds']);
        $this->assertNull($response['range_low_seconds']);
        $this->assertNull($response['range_high_seconds']);
        $this->assertFalse($response['is_personalized']);
        $this->assertNull($response['personal_solve_count']);
        $this->assertNull($response['last_time_seconds']);
        $this->assertNull($response['difficulty_score']);
        $this->assertNull($response['difficulty_level']);
        $this->assertNull($response['difficulty_confidence']);
    }

    /**
     * The "time predictions" opt-out hides the prediction but not the difficulty -
     * the same split as templates/puzzle/_difficulty_section.html.twig.
     */
    public function testOptedOutMemberGetsDifficultyButNoPrediction(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.0263, MetricConfidence::Medium);
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($response['predicted_seconds']);
        $this->assertFalse($response['is_personalized']);
        $this->assertNull($response['personal_solve_count']);
        $this->assertNull($response['last_time_seconds']);
        $this->assertSame(1.0263, $response['difficulty_score']);
        $this->assertSame('average', $response['difficulty_level']);
    }

    public function testPersonalAccessTokenWorks(): void
    {
        $browser = self::createClient();

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertTrue($response['is_personalized']);
        $this->assertSame(2100, $response['last_time_seconds']);
    }

    public function testNonExistentPuzzleReturnsNotFound(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['results:read']);
        $browser->request('GET', '/api/v1/me/puzzles/00000000-0000-0000-0000-000000000000/predicted-time');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $browser->request('GET', '/api/v1/me/puzzles/not-a-uuid/predicted-time');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMissingScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read', 'statistics:read']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * A client_credentials token carries results:read but no player - and no player
     * means no membership. /api/v1/me/* rejects it up front (403), never a 500.
     */
    public function testClientCredentialsTokenReturnsForbidden(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
            'scope' => 'results:read',
        ]);
        $this->assertResponseIsSuccessful();

        /** @var array{access_token: string} $tokenResponse */
        $tokenResponse = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        OAuth2TestHelper::addBearerToken($browser, $tokenResponse['access_token']);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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

        // The incremental recalculation may already have left a row behind - reuse it,
        // the puzzle id is the primary key.
        $difficulty = $entityManager->find(PuzzleDifficulty::class, $puzzleId) ?? new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, 20, new DateTimeImmutable());

        $entityManager->persist($difficulty);
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
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
