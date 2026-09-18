<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsletterUnsubscribeControllerTest extends WebTestCase
{
    public function testLandingPageShowsTheAddressWithoutUnsubscribing(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/newsletter/unsubscribe/' . $this->unsubscribeToken());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_EMAIL, (string) $browser->getResponse()->getContent());

        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->newsletterEnabled);
    }

    /**
     * The confirmation used to render the "unsubscribed" page straight from the POST - a 200 that Turbo Drive
     * discards, so visitors were unsubscribed but saw nothing happen and clicked the button again.
     */
    public function testConfirmingUnsubscribesAndRedirectsToTheConfirmationPage(): void
    {
        $browser = self::createClient();
        $token = $this->unsubscribeToken();

        $crawler = $browser->request('GET', '/en/newsletter/unsubscribe/' . $token);
        $form = $crawler->selectButton('Yes, unsubscribe me')->form();
        $browser->submit($form, [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertResponseRedirects('/en/newsletter/unsubscribe/' . $token . '/done');

        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->newsletterEnabled);

        $browser->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "You're unsubscribed");
        // Links to the e-mail preferences capability URL, so no shared cache may keep it
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testConfirmingWithoutValidCsrfChangesNothing(): void
    {
        $browser = self::createClient();
        $token = $this->unsubscribeToken();

        // No Origin/Referer header and no double-submit cookie: stateless CSRF fails
        $browser->request('POST', '/en/newsletter/unsubscribe/' . $token . '/confirm', [
            '_token' => 'forged',
        ]);

        self::assertResponseRedirects('/en/newsletter/unsubscribe/' . $token);

        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->newsletterEnabled);
    }

    public function testConfirmingWithGarbageTokenShowsInvalidTokenPage(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/newsletter/unsubscribe/not-a-token/confirm', [
            '_token' => 'csrf-token',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testConfirmationPageWithGarbageTokenShowsInvalidTokenPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/newsletter/unsubscribe/not-a-token/done');

        self::assertResponseStatusCodeSame(404);
    }

    private function unsubscribeToken(): string
    {
        $tokenSigner = self::getContainer()->get(NewsletterTokenSigner::class);

        return $tokenSigner->generateUnsubscribeToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );
    }
}
