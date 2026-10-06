<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryApiUsageCounter;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use SpeedPuzzling\Web\Value\ApiUsageSnapshot;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiUsageSubscriberTest extends WebTestCase
{
    public function testPersonalAccessTokenRequestIsCounted(): void
    {
        $browser = self::createClient();
        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        $tokenId = $this->tokenIdOf($browser, $token);

        PatTestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        self::assertResponseIsSuccessful();

        $records = $this->records($browser);
        self::assertCount(1, $records);
        self::assertSame(ApiCallerKind::PersonalAccessToken, $records[0]->caller->kind);
        self::assertSame($tokenId, $records[0]->caller->personalAccessTokenId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $records[0]->caller->playerId);
        self::assertSame('GET /api/v1/me', $records[0]->operation);
        self::assertSame(ApiStatusClass::Success, $records[0]->statusClass);
        self::assertGreaterThanOrEqual(0, $records[0]->durationMs);
    }

    public function testOAuth2UserRequestIsCountedForTheAppAndThePlayer(): void
    {
        $browser = self::createClient();
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read', 'results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/results');

        self::assertResponseIsSuccessful();

        $records = $this->records($browser);
        self::assertCount(1, $records);
        self::assertSame(ApiCallerKind::OAuth2User, $records[0]->caller->kind);
        self::assertSame(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, $records[0]->caller->oauth2ClientIdentifier);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $records[0]->caller->playerId);
        // The URI template, never the concrete URL
        self::assertSame('GET /api/v1/players/{playerId}/results', $records[0]->operation);
    }

    public function testClientCredentialsRejectedByTheFirewallIsCountedWithItsRequestType(): void
    {
        $browser = self::createClient();
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
        );

        // /me needs a player behind the token: access_control answers 403 before any controller
        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $records = $this->records($browser);
        self::assertCount(1, $records);
        self::assertSame(ApiCallerKind::OAuth2Client, $records[0]->caller->kind);
        self::assertNull($records[0]->caller->playerId);
        self::assertSame('GET /api/v1/me', $records[0]->operation);
        self::assertSame(ApiStatusClass::ClientError, $records[0]->statusClass);
    }

    public function testUnauthenticatedRequestsAreNotCounted(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->records($browser));

        PatTestHelper::addBearerToken($browser, 'msp_pat_' . str_repeat('0', 48));
        $browser->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->records($browser));
    }

    public function testWebPagesAreNotCounted(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/faq');

        self::assertSame([], $this->records($browser));
    }

    /**
     * FrankenPHP worker mode: one kernel serves request after request. The second
     * request must be counted for its own caller, never the first one's.
     */
    public function testNothingLeaksIntoTheNextRequestOfTheSameKernel(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $patToken = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        $clientToken = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
        );

        PatTestHelper::addBearerToken($browser, $patToken);
        $browser->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();

        OAuth2TestHelper::addBearerToken($browser, $clientToken);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();

        $browser->setServerParameter('HTTP_AUTHORIZATION', '');
        $browser->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $records = $this->records($browser);
        self::assertCount(2, $records);
        self::assertSame(ApiCallerKind::PersonalAccessToken, $records[0]->caller->kind);
        self::assertSame(ApiCallerKind::OAuth2Client, $records[1]->caller->kind);
        self::assertNull($records[1]->caller->playerId);
        self::assertSame('GET /api/v1/players/{playerId}', $records[1]->operation);
    }

    public function testAFailingCounterNeverBreaksTheRequest(): void
    {
        $browser = self::createClient();
        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);

        self::getContainer()->set(InMemoryApiUsageCounter::class, new class implements ApiUsageCounter {
            public function record(ApiRequestRecord $record): void
            {
                throw new \RuntimeException('Redis is down');
            }

            public function snapshot(DateTimeImmutable $day): ApiUsageSnapshot
            {
                throw new \RuntimeException('Redis is down');
            }
        });

        PatTestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<ApiRequestRecord>
     */
    private function records(KernelBrowser $browser): array
    {
        // Test-only service (config/services_test.php) - the dev container phpstan reads has the Redis one
        $counter = $browser->getContainer()->get(InMemoryApiUsageCounter::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        self::assertInstanceOf(InMemoryApiUsageCounter::class, $counter);

        return $counter->records();
    }

    private function tokenIdOf(KernelBrowser $browser, string $plainToken): string
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $browser->getContainer()->get('doctrine.dbal.default_connection');
        $id = $connection->fetchOne('SELECT id FROM personal_access_token WHERE token_hash = :hash', ['hash' => hash('sha256', $plainToken)]);
        self::assertIsString($id);

        return $id;
    }
}
