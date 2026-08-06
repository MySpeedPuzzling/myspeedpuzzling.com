<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PredictedTimeEndpointTest extends WebTestCase
{
    public function testMeWithoutActiveMembershipReturnsNullPrediction(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR, // No active membership in fixtures
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{puzzle_id: string, predicted_seconds: null|int, is_personalized: bool} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);
        $this->assertNull($response['predicted_seconds']);
        $this->assertFalse($response['is_personalized']);
    }

    public function testMeWithActiveMembershipAndHistoryReturnsPersonalizedPrediction(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            // Active membership, and fixtures give this player solving history on this puzzle
            // (see docs "PUZZLE_500_01 statistics test" in .claude/fixtures.md).
            PlayerFixture::PLAYER_WITH_STRIPE,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);
        $this->assertIsInt($response['predicted_seconds']);
        $this->assertGreaterThan(0, $response['predicted_seconds']);
        $this->assertTrue($response['is_personalized']);

        // No puzzle_difficulty fixture exists yet for PUZZLE_500_01, so these come back null -
        // this only guards against the fields disappearing from the response entirely.
        $this->assertArrayHasKey('difficulty_score', $response);
        $this->assertArrayHasKey('difficulty_level', $response);
        $this->assertArrayHasKey('difficulty_confidence', $response);
    }

    public function testMeNonExistentPuzzleReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/puzzles/00000000-0000-0000-0000-000000000000/predicted-time');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMeWithoutRequiredScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'], // Wrong scope
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testForOtherPlayerRespectsPrivacy(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_WITH_STRIPE,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request(
            'GET',
            '/api/v1/players/' . PlayerFixture::PLAYER_PRIVATE . '/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time',
        );

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{puzzle_id: string, predicted_seconds: null|int} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $response['puzzle_id']);
        $this->assertNull($response['predicted_seconds']);
    }

    public function testForOtherPlayerNonExistentPlayerReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_WITH_STRIPE,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request(
            'GET',
            '/api/v1/players/00000000-0000-0000-0000-000000000000/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/predicted-time',
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
