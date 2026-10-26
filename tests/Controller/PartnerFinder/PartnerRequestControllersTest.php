<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\PartnerFinder;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * "I'd like to puzzle with you" over the web (docs/features/partner-finder.md D6): the send route answers a block
 * exactly like a sent request, the reply page shows the request to the listing's owner only and its POST leads into
 * the chat, the notifications list offers Reply.
 */
final class PartnerRequestControllersTest extends WebTestCase
{
    private const string SAME_ORIGIN = 'http://localhost';
    private const string RETURN_PATH = '/en/events/partner-finder-open';

    public function testABlockedSenderGetsTheSameAnswerAsAnyOther(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR blocks PLAYER_PRIVATE - her request is silently not sent
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $this->send($browser);
        $this->assertResponseRedirects(self::RETURN_PATH, 303);
        $blockedFlash = $this->flashes($browser);

        // PLAYER_ADMIN's goes
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->send($browser);
        $this->assertResponseRedirects(self::RETURN_PATH, 303);
        $sentFlash = $this->flashes($browser);

        self::assertSame($sentFlash, $blockedFlash);
        self::assertArrayHasKey('success', $sentFlash);

        $connection = $browser->getContainer()->get(Connection::class);
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM partner_request WHERE from_player_id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM partner_request WHERE from_player_id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]));
    }

    public function testOnlyTheListingsOwnerSeesTheReplyPage(): void
    {
        $browser = self::createClient();
        $url = '/en/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply';

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $crawler->filter('h1')->text());
        self::assertStringContainsString('Hi John, I would love to pair up!', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="partner-request-reply-form"]'));
    }

    public function testReplyLeadsIntoTheChat(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // CONVERSATION_PENDING (PLAYER_WITH_STRIPE → PLAYER_REGULAR) becomes the chat
        $browser->request(
            'POST',
            '/en/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply',
            ['_token' => 'csrf-token'],
            server: ['HTTP_ORIGIN' => self::SAME_ORIGIN],
        );

        $this->assertResponseRedirects('/en/messages/' . ConversationFixture::CONVERSATION_PENDING, 303);
    }

    public function testAReplyToAnAskerWhoBlocksTheOwnerSinceOpensNoChatAndSaysNothingMore(): void
    {
        $browser = self::createClient();
        $connection = $browser->getContainer()->get(Connection::class);

        // PLAYER_WITH_STRIPE asked, then blocked the listed puzzler
        $connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply';
        $browser->request('POST', $url, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN]);

        $this->assertResponseRedirects($url, 303);
        self::assertSame(['warning' => ["This request can't be answered with a chat any more."]], $this->flashes($browser));
        self::assertSame('pending', $connection->fetchOne('SELECT status FROM conversation WHERE id = :id', ['id' => ConversationFixture::CONVERSATION_PENDING]));
        self::assertNull($connection->fetchOne('SELECT replied_at FROM partner_request WHERE id = :id', ['id' => PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR]));
    }

    public function testAFailedCsrfCheckChangesNothing(): void
    {
        $browser = self::createClient();
        $connection = $browser->getContainer()->get(Connection::class);
        // Sec-Fetch-Site decides alone - an Origin header would lose to the Referer the test browser sends along
        $crossOrigin = ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example'];

        // Send
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('POST', '/en/partners/listing/' . PartnerFinderFixture::LISTING_REGULAR_PAIR . '/request', [
            '_token' => 'csrf-token',
            'kind' => 'pair',
            'return' => self::RETURN_PATH,
        ], server: $crossOrigin);
        $this->assertResponseRedirects(self::RETURN_PATH, 303);
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM partner_request WHERE from_player_id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]));
        self::assertSame(0, $connection->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :competition AND player_id = :player',
            ['competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'player' => PlayerFixture::PLAYER_ADMIN],
        ));

        // Close
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('POST', '/en/partners/listing/' . PartnerFinderFixture::LISTING_REGULAR_PAIR . '/close', [
            '_token' => 'csrf-token',
            'reason' => 'found',
            'return' => self::RETURN_PATH,
        ], server: $crossOrigin);
        $this->assertResponseRedirects(self::RETURN_PATH, 303);
        self::assertNull($connection->fetchOne('SELECT closed_at FROM partner_listing WHERE id = :id', ['id' => PartnerFinderFixture::LISTING_REGULAR_PAIR]));

        // Reply
        $browser->request('POST', '/en/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply', ['_token' => 'csrf-token'], server: $crossOrigin);
        $this->assertResponseRedirects('/en/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply', 303);
        self::assertSame('pending', $connection->fetchOne('SELECT status FROM conversation WHERE id = :id', ['id' => ConversationFixture::CONVERSATION_PENDING]));
        self::assertNull($connection->fetchOne('SELECT replied_at FROM partner_request WHERE id = :id', ['id' => PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR]));
    }

    public function testTheNotificationsListOffersReply(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->send($browser);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('would like to puzzle with you as a pair at Partner Finder Open', $content);
        self::assertGreaterThan(0, $crawler->filter('form[action*="/partners/request/"][action$="/reply"]')->count());
    }

    private function send(KernelBrowser $browser): void
    {
        $browser->request(
            'POST',
            '/en/partners/listing/' . PartnerFinderFixture::LISTING_REGULAR_PAIR . '/request',
            ['_token' => 'csrf-token', 'kind' => 'pair', 'note' => 'Hello!', 'return' => self::RETURN_PATH],
            server: ['HTTP_ORIGIN' => self::SAME_ORIGIN],
        );
    }

    /**
     * The flash messages the answer left, each once (the test's session keeps earlier ones)
     *
     * @return array<string, list<string>>
     */
    private function flashes(KernelBrowser $browser): array
    {
        $session = $browser->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        /** @var array<string, list<string>> $flashes */
        $flashes = $session->getFlashBag()->peekAll();

        return array_map(static fn (array $messages): array => array_values(array_unique($messages)), $flashes);
    }
}
