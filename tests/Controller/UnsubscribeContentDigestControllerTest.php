<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ContentDigestUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One-click unsubscribe from the weekly content digest - the same contract as the unread-messages digest
 * (DigestEmailsUnsubscribeControllerTest): the signed link works without signing in, a POST switches the digest off,
 * a GET (a mail scanner) changes nothing.
 */
final class UnsubscribeContentDigestControllerTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    public function testOneClickPostSwitchesOffOnlyTheContentDigest(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        // What a mail client sends for List-Unsubscribe-Post (RFC 8058) - answered without a redirect
        $browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);

        $this->assertResponseStatusCodeSame(200);
        self::assertSame('Unsubscribed.', $browser->getResponse()->getContent());

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER);
        self::assertSame(ContentDigestFrequency::None, $player->contentDigestFrequency);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertTrue($player->newsletterEnabled);

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="content-digest-unsubscribed"]'));
        self::assertCount(0, $crawler->filter('[data-testid="content-digest-unsubscribe-form"]'));
    }

    public function testOpeningTheLinkChangesNothingAndTheButtonRedirectsBack(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        // GET never unsubscribes (link prefetchers!) - it shows the confirm button
        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(
            ContentDigestFrequency::Weekly,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->contentDigestFrequency,
        );

        // The page's button posts to the very same signed link
        $form = $crawler->filter('[data-testid="content-digest-unsubscribe-form"]')->form();
        $browser->submit($form);

        $this->assertResponseRedirects($url, 303);
        self::assertSame(
            ContentDigestFrequency::None,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->contentDigestFrequency,
        );
    }

    public function testAnUnsignedOrTamperedLinkIsNotFound(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        $browser->request('POST', '/en/weekly-digest/unsubscribe/' . self::PLAYER);
        $this->assertResponseStatusCodeSame(404);

        // Somebody else's id with this player's signature
        $browser->request('POST', str_replace(self::PLAYER, PlayerFixture::PLAYER_ADMIN, $url));
        $this->assertResponseStatusCodeSame(404);

        // An unread-messages digest signature does not open this page (different path, different signature)
        $browser->request('POST', str_replace('/weekly-digest/', '/message-emails/', $url));
        $this->assertResponseStatusCodeSame(404);

        self::assertSame(
            ContentDigestFrequency::Weekly,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->contentDigestFrequency,
        );
    }

    private function signedUrl(KernelBrowser $browser): string
    {
        return $browser->getContainer()->get(ContentDigestUnsubscribeUrl::class)->forPlayer(self::PLAYER, 'en');
    }
}
