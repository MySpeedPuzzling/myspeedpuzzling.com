<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ApiCaller;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiUsageControllerTest extends WebTestCase
{
    public function testPlayerSeesTheUsageOfTheirOwnToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $tokenId = $this->createTokenWithUsage($browser, PlayerFixture::PLAYER_REGULAR, 'Spreadsheet', 42);

        $crawler = $browser->request('GET', '/en/account/api-usage?show=pat-' . $tokenId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API usage');
        self::assertStringContainsString('Spreadsheet', $crawler->filter('a[aria-current="true"]')->text());
        self::assertStringContainsString('GET /api/v1/me', $crawler->filter('table')->text());
        self::assertSame('42', trim($crawler->filter('.card .h4')->first()->text()));
    }

    public function testSomebodyElsesTokenIsNotSelectable(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $foreignTokenId = $this->createTokenWithUsage($browser, PlayerFixture::PLAYER_PRIVATE, 'Not yours', 99);

        $crawler = $browser->request('GET', '/en/account/api-usage?show=pat-' . $foreignTokenId);

        self::assertResponseIsSuccessful();
        // Falls back to the player's own usage, where the foreign token's requests are not
        self::assertStringNotContainsString('Not yours', $crawler->filter('body')->text());
        self::assertStringNotContainsString('99', $crawler->filter('body')->text());
    }

    public function testAnOwnAppShowsItsUsageOverAllUsers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE oauth2_client_request SET status = 'approved', client_identifier = :client WHERE player_id = :player AND client_name = 'Test Confidential App'",
            ['client' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, 'player' => PlayerFixture::PLAYER_REGULAR],
        );
        $this->insertUsage($browser, ApiCaller::oauth2User(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, PlayerFixture::PLAYER_PRIVATE), 5);
        $this->insertUsage($browser, ApiCaller::oauth2Client(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID), 3);

        $crawler = $browser->request('GET', '/en/account/api-usage?show=own-' . OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);

        self::assertResponseIsSuccessful();
        self::assertSame('8', trim($crawler->filter('.card .h4')->first()->text()));
        // Never per user
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());
    }

    public function testEditProfileLinksEveryTokenToItsUsage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $tokenId = $this->createTokenWithUsage($browser, PlayerFixture::PLAYER_REGULAR, 'Spreadsheet', 7);
        // Tokens are listed once the fair use policy is accepted
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET fair_use_policy_accepted_at = NOW() WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a[href$="show=pat-' . $tokenId . '"]');
        self::assertCount(1, $link);
        self::assertSame('7 requests in the last 30 days', trim($link->text()));
    }

    public function testGuestsAreSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/account/api-usage');

        self::assertResponseRedirects();
    }

    public function testAdminSeesEveryCaller(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->createTokenWithUsage($browser, PlayerFixture::PLAYER_REGULAR, 'Spreadsheet', 11);
        $this->insertUsage($browser, ApiCaller::oauth2Client(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID), 3);

        $crawler = $browser->request('GET', '/admin/api-usage');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table')->first()->text();
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME . ' #', $text);
        self::assertStringContainsString('Spreadsheet', $text);

        $crawler = $browser->request('GET', '/admin/api-usage?kind=client');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Spreadsheet', $crawler->filter('table')->first()->text());
    }

    public function testPlayersCannotOpenTheAdminPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/admin/api-usage');

        self::assertResponseStatusCodeSame(403);
    }

    private function createTokenWithUsage(KernelBrowser $browser, string $playerId, string $name, int $requests): string
    {
        $tokenId = Uuid::uuid7()->toString();

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->insert('personal_access_token', [
            'id' => $tokenId,
            'player_id' => $playerId,
            'name' => $name,
            'token_hash' => hash('sha256', $tokenId),
            'token_prefix' => 'msp_pat_abcd',
            'fair_use_policy_accepted_at' => '2026-09-01 00:00:00',
            'created_at' => '2026-09-01 00:00:00',
        ]);

        $this->insertUsage($browser, ApiCaller::personalAccessToken($tokenId, $playerId), $requests);

        return $tokenId;
    }

    private function insertUsage(KernelBrowser $browser, ApiCaller $caller, int $requests): void
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        /** @var ClockInterface $clock */
        $clock = $browser->getContainer()->get(ClockInterface::class);
        $today = $clock->now()->setTimezone(new DateTimeZone('UTC'));

        $connection->insert('api_usage_day', [
            'id' => Uuid::uuid7()->toString(),
            'day' => $today->format('Y-m-d'),
            'caller_key' => $caller->key(),
            'player_id' => $caller->playerId,
            'personal_access_token_id' => $caller->personalAccessTokenId,
            'oauth2_client_identifier' => $caller->oauth2ClientIdentifier,
            'operation' => 'GET /api/v1/me',
            'status_class' => '2xx',
            'requests' => $requests,
            'duration_ms_total' => $requests * 10,
        ]);
        $connection->insert('api_caller_day', [
            'id' => Uuid::uuid7()->toString(),
            'day' => $today->format('Y-m-d'),
            'caller_key' => $caller->key(),
            'player_id' => $caller->playerId,
            'personal_access_token_id' => $caller->personalAccessTokenId,
            'oauth2_client_identifier' => $caller->oauth2ClientIdentifier,
            'peak_requests_per_minute' => 2,
            'last_request_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
        ]);
    }
}
