<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ResultEmails;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One-click unsubscribe from the "Your results" e-mails (docs/features/duplicate-results.md, "Sending"): the signed
 * link works without signing in, a POST switches the e-mails off, a GET (a mail scanner) changes nothing.
 */
final class ResultEmailsUnsubscribeControllerTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    public function testOneClickPostSwitchesOffOnlyTheResultEmails(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        // What a mail client sends for List-Unsubscribe-Post (RFC 8058) - answered without a redirect
        $browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);

        $this->assertResponseStatusCodeSame(200);
        self::assertFalse($browser->getResponse()->isRedirection());
        self::assertSame('Unsubscribed.', $browser->getResponse()->getContent());

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER);
        self::assertFalse($player->resultEmailsEnabled);
        self::assertTrue($player->newsletterEnabled);
        self::assertTrue($player->emailNotificationsEnabled);

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="result-emails-unsubscribed"]'));
        self::assertCount(0, $crawler->filter('[data-testid="result-emails-unsubscribe-form"]'));
    }

    public function testOpeningTheLinkChangesNothing(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $browser->getResponse()->headers->getCookies(), 'No session for an anonymous page');
        self::assertTrue($browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->resultEmailsEnabled);

        // The page's button posts to the very same signed link
        $form = $crawler->filter('[data-testid="result-emails-unsubscribe-form"]')->form();
        $browser->submit($form);

        $this->assertResponseRedirects($url, 303);
        self::assertFalse($browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->resultEmailsEnabled);
    }

    public function testAnUnsignedOrTamperedLinkIsNotFound(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        $browser->request('POST', '/en/result-emails/unsubscribe/' . self::PLAYER);
        $this->assertResponseStatusCodeSame(404);

        // Somebody else's id with this player's signature
        $browser->request('POST', str_replace(self::PLAYER, PlayerFixture::PLAYER_ADMIN, $url));
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', $url . 'x');
        $this->assertResponseStatusCodeSame(404);

        self::assertTrue($browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->resultEmailsEnabled);
        self::assertTrue($browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN)->resultEmailsEnabled);
    }

    private function signedUrl(KernelBrowser $browser): string
    {
        return $browser->getContainer()->get(ResultEmailsUnsubscribeUrl::class)->forPlayer(self::PLAYER, 'en');
    }
}
