<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Message\InviteModerator;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

final class ModeratorGuideControllerTest extends WebTestCase
{
    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/moderator-guide');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAPlayerWithoutAnInvitationCannotOpenIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/moderator-guide');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testInvitedPlayerReadsTheGuideWithThePledge(): void
    {
        $browser = $this->invitedPlayer();

        $browser->request('GET', '/en/moderator-guide');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Moderator guide');
        self::assertSelectorTextContains('body', 'Leave it better than you found it.');
        self::assertSelectorExists('#accept input[type="checkbox"][name="accept_moderator_invitation_form[pledge]"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testThePledgeMustBeTicked(): void
    {
        $browser = $this->invitedPlayer();
        $browser->request('GET', '/en/moderator-guide');

        $browser->submitForm('Accept and become a moderator', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->isModerator());
    }

    public function testDeclining(): void
    {
        $browser = $this->invitedPlayer();
        $browser->request('GET', '/en/moderator-guide');

        $browser->submitForm('Not now - decline the invitation');

        self::assertResponseRedirects();
        self::assertFalse($browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->isModerator());

        $browser->request('GET', '/en/moderator-guide');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testModeratorComesBackToTheGuideWithoutThePledge(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/moderator-guide');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#accept');
        // ... and finds it in the key menu
        self::assertCount(1, $crawler->filter('.dropdown-menu a[href="/en/moderator-guide"]'));
    }

    public function testAdminReadsTheGuide(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/moderator-guide');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#accept');
    }

    private function invitedPlayer(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new InviteModerator(Uuid::uuid7(), PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }
}
