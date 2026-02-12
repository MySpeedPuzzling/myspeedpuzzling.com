<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReportConversationControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED . '/report', [
            'reason' => 'Inappropriate content',
        ]);

        $this->assertResponseRedirects();
    }

    public function testParticipantCanReport(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN is recipient of CONVERSATION_MARKETPLACE
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/messages/' . ConversationFixture::CONVERSATION_DENIED . '/report', [
            'reason' => 'Spam content',
        ]);

        $this->assertResponseRedirects();
    }

    public function testEmptyReasonDoesNotCreateReport(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/messages/' . ConversationFixture::CONVERSATION_ACCEPTED . '/report', [
            'reason' => '',
        ]);

        $this->assertResponseRedirects();
    }
}
