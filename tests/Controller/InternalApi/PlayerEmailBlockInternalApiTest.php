<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "E-mail blocks and messages to players": block a player's address by hand, read it,
 * lift it
 */
final class PlayerEmailBlockInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string UNKNOWN_PLAYER = '018d0000-0000-0000-0000-0000000002ff';

    public function testBlocksReadsAndLiftsABlockOnThePlayersCurrentAddress(): void
    {
        $browser = self::createClient();
        $uri = $this->uri(PlayerFixture::PLAYER_REGULAR);

        $block = self::callInternalApi($browser, 'PUT', $uri, ['reason' => 'The domain of your address does not exist.']);
        self::assertResponseIsSuccessful();
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $block['email']);
        self::assertSame('manual', $block['source']);
        self::assertSame('The domain of your address does not exist.', $block['reason']);

        // Again with another reason = the new reason, still one row
        self::callInternalApi($browser, 'PUT', $uri, ['reason' => 'Mail to your address keeps coming back.']);
        self::assertResponseIsSuccessful();
        $read = self::callInternalApi($browser, 'GET', $uri);
        self::assertResponseIsSuccessful();
        self::assertSame('Mail to your address keeps coming back.', $read['reason']);
        self::assertEquals(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM email_suppression'));

        self::callInternalApi($browser, 'DELETE', $uri);
        self::assertResponseStatusCodeSame(204);
        self::assertFalse(self::getContainer()->get(EmailSuppressionRepository::class)->isSuppressed(PlayerFixture::PLAYER_REGULAR_EMAIL));

        self::callInternalApi($browser, 'GET', $uri);
        self::assertResponseStatusCodeSame(404);
        self::callInternalApi($browser, 'DELETE', $uri);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARequiredReasonAndAKnownPlayer(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'PUT', $this->uri(PlayerFixture::PLAYER_REGULAR), ['reason' => '  ']);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'PUT', $this->uri(self::UNKNOWN_PLAYER), ['reason' => 'Whatever']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNeedsTheToken(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'PUT', $this->uri(PlayerFixture::PLAYER_REGULAR), ['reason' => 'x'], token: null);
        self::assertResponseStatusCodeSame(401);
    }

    private function uri(string $playerId): string
    {
        return '/internal-api/players/' . $playerId . '/email-block';
    }
}
