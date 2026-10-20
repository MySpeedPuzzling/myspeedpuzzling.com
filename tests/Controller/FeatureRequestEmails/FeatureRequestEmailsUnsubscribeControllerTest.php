<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FeatureRequestEmails;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\FeatureRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One-click unsubscribe from the feature request e-mails: the signed link works without signing in, a POST sets them
 * to None, a GET (a mail scanner) changes nothing.
 */
final class FeatureRequestEmailsUnsubscribeControllerTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    public function testOneClickPostSwitchesOffOnlyTheFeatureRequestEmails(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        // What a mail client sends for List-Unsubscribe-Post (RFC 8058) - answered without a redirect
        $browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);

        $this->assertResponseStatusCodeSame(200);
        self::assertSame('Unsubscribed.', $browser->getResponse()->getContent());

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER);
        self::assertSame(FeatureRequestEmails::None, $player->featureRequestEmails);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertTrue($player->newsletterEnabled);
        self::assertTrue($player->resultEmailsEnabled);

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="feature-request-emails-unsubscribed"]'));
        self::assertCount(0, $crawler->filter('[data-testid="feature-request-emails-unsubscribe-form"]'));
    }

    public function testOpeningTheLinkChangesNothing(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(
            FeatureRequestEmails::Upvoted,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->featureRequestEmails,
        );

        $form = $crawler->filter('[data-testid="feature-request-emails-unsubscribe-form"]')->form();
        $browser->submit($form);

        $this->assertResponseRedirects($url, 303);
        self::assertSame(
            FeatureRequestEmails::None,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->featureRequestEmails,
        );
    }

    public function testAnUnsignedOrTamperedLinkIsNotFound(): void
    {
        $browser = self::createClient();
        $url = $this->signedUrl($browser);

        $browser->request('POST', '/en/feature-request-emails/unsubscribe/' . self::PLAYER);
        $this->assertResponseStatusCodeSame(404);

        $browser->request('POST', str_replace(self::PLAYER, PlayerFixture::PLAYER_ADMIN, $url));
        $this->assertResponseStatusCodeSame(404);

        // A digest-unsubscribe signature does not open this page (different path, different signature)
        $browser->request('POST', str_replace('/feature-request-emails/', '/message-emails/', $url));
        $this->assertResponseStatusCodeSame(404);

        self::assertSame(
            FeatureRequestEmails::Upvoted,
            $browser->getContainer()->get(PlayerRepository::class)->get(self::PLAYER)->featureRequestEmails,
        );
    }

    private function signedUrl(KernelBrowser $browser): string
    {
        return $browser->getContainer()->get(FeatureRequestEmailsUnsubscribeUrl::class)->forPlayer(self::PLAYER, 'en');
    }
}
