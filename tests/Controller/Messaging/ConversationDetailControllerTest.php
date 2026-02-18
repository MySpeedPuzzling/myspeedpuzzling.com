<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConversationDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED);

        $this->assertResponseRedirects();
    }

    public function testParticipantCanView(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR is initiator of CONVERSATION_ACCEPTED
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED);

        $this->assertResponseIsSuccessful();
    }

    public function testRecipientCanView(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN is recipient of CONVERSATION_ACCEPTED
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED);

        $this->assertResponseIsSuccessful();
    }

    public function testNonParticipantGets403(): void
    {
        $browser = self::createClient();

        // PLAYER_PRIVATE is not a participant of CONVERSATION_ACCEPTED
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPendingConversationVisibleToParticipants(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR is recipient of CONVERSATION_PENDING
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_PENDING);

        $this->assertResponseIsSuccessful();
    }

    public function testConversationDetailShowsRateButtonForCompletedTransaction(): void
    {
        $browser = self::createClient();

        // WITH_FAVORITES is initiator of CONVERSATION_MARKETPLACE_COMPLETED
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_MARKETPLACE_COMPLETED);

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a:contains("Rate transaction")')->count());
    }
}
