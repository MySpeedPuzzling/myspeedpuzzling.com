<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class CurrentUserEndpointTest extends WebTestCase
{
    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithInvalidTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        OAuth2TestHelper::addBearerToken($browser, 'invalid-token');
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithValidTokenReturnsUserProfile(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{id: string, name: string, code?: string, avatar?: string, country?: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['id']);
        $this->assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $response['name']);
        $this->assertArrayHasKey('code', $response);
        $this->assertArrayHasKey('avatar', $response);
        $this->assertArrayHasKey('country', $response);
    }

    public function testEmailIsNullWithoutEmailReadScope(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('email', $response);
        $this->assertNull($response['email']);
    }

    public function testEmailIsReturnedWithEmailReadScope(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read', 'email:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{email: null|string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $response['email']);
    }

    public function testEmailReadScopeAloneCannotAccessEndpoint(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['email:read'], // Missing profile:read which gates the endpoint
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWithoutRequiredScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'], // Wrong scope
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWorksForPrivatePlayer(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_PRIVATE,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{id: string, name: string, is_private: bool} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $response['id']);
        $this->assertTrue($response['is_private']);
    }

    /**
     * A client_credentials token is authenticated (as the bundle's
     * ClientCredentialsUser) but has no player behind it. It used to pass
     * IS_AUTHENTICATED_FULLY and die on assert($user instanceof ApiUser) - 500.
     */
    public function testClientCredentialsTokenReturnsForbidden(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
            'scope' => 'profile:read',
        ]);

        $this->assertResponseIsSuccessful();

        /** @var array{access_token: string} $tokenResponse */
        $tokenResponse = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        OAuth2TestHelper::addBearerToken($browser, $tokenResponse['access_token']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * membership_ends_at + the three opt-out flags let an app tell a members-only
     * null block apart ("upgrade" vs "you opted out"). PLAYER_WITH_STRIPE is a member
     * (billing period ends 30 days from now), nothing opted out in the fixtures.
     */
    public function testMemberGetsMembershipEndAndDefaultFlagsViaOAuth2(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertTrue($response['has_active_membership']);
        $this->assertIsString($response['membership_ends_at']);
        $this->assertSame(
            $response['membership_ends_at'],
            (new DateTimeImmutable($response['membership_ends_at']))->format('c'),
            'membership_ends_at must be ISO-8601 (DateTimeInterface::ATOM)',
        );
        $this->assertGreaterThan(new DateTimeImmutable(), new DateTimeImmutable($response['membership_ends_at']));
        $this->assertFalse($response['time_predictions_opted_out']);
        $this->assertFalse($response['ranking_opted_out']);
        $this->assertFalse($response['streak_opted_out']);
    }

    public function testMemberGetsMembershipEndAndDefaultFlagsViaPersonalAccessToken(): void
    {
        $browser = self::createClient();

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $response['id']);
        $this->assertTrue($response['has_active_membership']);
        $this->assertIsString($response['membership_ends_at']);
        $this->assertGreaterThan(new DateTimeImmutable(), new DateTimeImmutable($response['membership_ends_at']));
        $this->assertFalse($response['time_predictions_opted_out']);
        $this->assertFalse($response['ranking_opted_out']);
        $this->assertFalse($response['streak_opted_out']);
    }

    /**
     * GetPlayerProfile coalesces a missing membership to the 1970 epoch - the API
     * must still say null, not "1970-01-01T00:00:00+00:00".
     */
    public function testNonMemberHasNullMembershipEnd(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['has_active_membership']);
        $this->assertArrayHasKey('membership_ends_at', $response);
        $this->assertNull($response['membership_ends_at']);
        $this->assertFalse($response['time_predictions_opted_out']);
        $this->assertFalse($response['ranking_opted_out']);
        $this->assertFalse($response['streak_opted_out']);
    }

    public function testNonMemberHasNullMembershipEndViaPersonalAccessToken(): void
    {
        $browser = self::createClient();

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['has_active_membership']);
        $this->assertNull($response['membership_ends_at']);
    }

    /**
     * membership_ends_at is the end of the *current* membership, not a history: once the
     * membership has run out the API says null, not the date it expired on.
     */
    public function testExpiredMembershipHasNullMembershipEnd(): void
    {
        $browser = self::createClient();
        $this->expireMembership($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['has_active_membership']);
        $this->assertNull($response['membership_ends_at']);
    }

    public function testOptOutFlagsReflectThePlayerSettingsViaOAuth2(): void
    {
        $browser = self::createClient();
        $this->optOut($browser, PlayerFixture::PLAYER_WITH_STRIPE, timePredictions: true, ranking: true, streak: true);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertTrue($response['has_active_membership']);
        $this->assertTrue($response['time_predictions_opted_out']);
        $this->assertTrue($response['ranking_opted_out']);
        $this->assertTrue($response['streak_opted_out']);
    }

    public function testOptOutFlagsReflectThePlayerSettingsViaPersonalAccessToken(): void
    {
        $browser = self::createClient();
        $this->optOut($browser, PlayerFixture::PLAYER_REGULAR, timePredictions: true, ranking: true, streak: true);

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['has_active_membership']);
        $this->assertTrue($response['time_predictions_opted_out']);
        $this->assertTrue($response['ranking_opted_out']);
        $this->assertTrue($response['streak_opted_out']);
    }

    /**
     * Each flag is independent - one opt-out must not flip the others.
     */
    public function testSingleOptOutDoesNotAffectTheOtherFlags(): void
    {
        $browser = self::createClient();
        $this->optOut($browser, PlayerFixture::PLAYER_WITH_STRIPE, timePredictions: false, ranking: true, streak: false);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertFalse($response['time_predictions_opted_out']);
        $this->assertTrue($response['ranking_opted_out']);
        $this->assertFalse($response['streak_opted_out']);
    }

    /**
     * The four new fields come from the PlayerProfile the provider already loads -
     * the request must not issue a single extra query. Measured before the fields
     * were added: OAuth2 = 3 queries (player, access token, profile), PAT = 2
     * (token, profile). Only the request is counted - the token-creation queries
     * are flushed from the Doctrine debug log right before it.
     */
    public function testRequestQueryBudgetIsUnchangedViaOAuth2(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);
        $browser->enableProfiler();
        $this->resetQueryLog($browser);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $this->assertLessThanOrEqual(3, $this->queryCount($browser));
    }

    public function testRequestQueryBudgetIsUnchangedViaPersonalAccessToken(): void
    {
        $browser = self::createClient();

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        $browser->enableProfiler();
        $this->resetQueryLog($browser);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();
        $this->assertLessThanOrEqual(2, $this->queryCount($browser));
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

    private function optOut(KernelBrowser $browser, string $playerId, bool $timePredictions, bool $ranking, bool $streak): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        $player->changeTimePredictionsOptedOut($timePredictions);
        $player->changeRankingOptedOut($ranking);
        $player->changeStreakOptedOut($streak);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * Cancelled with a billing period that already ran out: ends_at in the past, no
     * billing period, no grant - the same row an expired Stripe subscription leaves.
     */
    private function expireMembership(KernelBrowser $browser, string $playerId): void
    {
        $entityManager = $this->entityManager($browser);

        $membership = $entityManager->getRepository(Membership::class)->findOneBy(['player' => $playerId]);
        $this->assertNotNull($membership);

        $membership->cancel(new DateTimeImmutable('-1 day'));
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * The first request of a test reuses the kernel that the token helpers already
     * booted, so the Doctrine data collector would also count the queries those
     * helpers issued. Empty the debug log so the profile covers the request only.
     */
    private function resetQueryLog(KernelBrowser $browser): void
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = $container->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();
    }

    private function queryCount(KernelBrowser $browser): int
    {
        $profile = $browser->getProfile();
        $this->assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        $this->assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
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
