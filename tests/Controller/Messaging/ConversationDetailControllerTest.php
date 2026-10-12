<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\AssertsInPageConfirmations;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConversationDetailControllerTest extends WebTestCase
{
    use AssertsInPageConfirmations;

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

    /**
     * GEST-13: blocking and reporting ask in the in-page dialog - the report form sits in a Bootstrap modal, the
     * dialog is appended inside it (assets/confirm_dialog.js).
     */
    public function testBlockAndReportAskInThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED);

        $this->assertResponseIsSuccessful();
        self::assertAsksInThePage($crawler, 2);

        $block = $crawler->filter('form[action*="/block-user/"] button[type="submit"]');
        self::assertCount(1, $block);
        self::assertNotEmpty($block->attr('data-turbo-confirm'));
        self::assertNotNull($block->attr('data-turbo-confirm-danger'));

        $report = $crawler->filter('#reportConversationModal form');
        self::assertCount(1, $report);
        self::assertNotEmpty($report->attr('data-turbo-confirm'));
        self::assertNotNull($report->attr('data-turbo-confirm-danger'));
        self::assertNull($report->attr('onsubmit'));
    }
}
