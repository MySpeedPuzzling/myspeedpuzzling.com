<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HubControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
    }

    public function testNewcomerSeesTheGettingStartedCardUntilTheyHideIt(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertCount(1, $crawler->filter('.getting-started'));
        // The regular fixture player has solving times: the core step is done, so the card is folded
        self::assertCount(1, $crawler->filter('.getting-started details'));
        self::assertCount(0, $crawler->filter('.getting-started details[open]'));

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'getting_started_checklist']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(0, $crawler->filter('.getting-started'));
    }

    public function testCardStaysOpenUntilTheFirstPuzzleIsLogged(): void
    {
        $browser = self::createClient();
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();
        $browser->submit($form, [
            $form->getName() . '[email]' => sprintf('hub.newcomer+%s@example.com', bin2hex(random_bytes(4))),
            $form->getName() . '[plainPassword]' => 'a-properly-long-passphrase',
        ]);

        // Every visit, not just the first one
        foreach ([1, 2] as $visit) {
            $crawler = $browser->request('GET', '/en/hub');
            self::assertCount(1, $crawler->filter('.getting-started details[open]'), sprintf('Visit %d', $visit));
        }
    }

    public function testAnonymousVisitorGetsNoGettingStartedCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/hub');

        self::assertCount(0, $crawler->filter('.getting-started'));
    }
}
