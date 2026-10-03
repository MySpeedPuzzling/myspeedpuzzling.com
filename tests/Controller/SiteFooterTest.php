<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * docs/features/site-footer.md - one footer for everybody; guests also get the newsletter form and
 * "Popular searches", a player only gets a newsletter line after switching the newsletter off.
 */
final class SiteFooterTest extends WebTestCase
{
    private const string PAGE = '/en/faq';
    private const string TURN_ON = '/en/newsletter/turn-on';

    public function testGuestGetsTheNewsletterFormAndPopularSearches(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $footer = $crawler->filter('footer.site-footer');
        self::assertCount(1, $footer->filter('.footer-newsletter form[action="/en/newsletter/subscribe"]'));
        self::assertCount(4, $footer->filter('.footer-popular details.footer-popular-group'));
        self::assertSame(['Discover', 'Track', 'Learn', 'MySpeedPuzzling'], $footer->filter('.footer-columns h2')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertCount(5, $footer->filter('.footer-columns .badge'), 'The "New" badges stay');
        self::assertCount(1, $footer->filter('.footer-columns a[href="/en/compare"] .badge'), 'Compare is new');
        self::assertCount(0, $footer->filter('.footer-newsletter-off'));
    }

    public function testEachPageIsLinkedOnceFromTheFooter(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        // The newsletter consent links the privacy policy in its text as well - that one is meant
        $hrefs = $crawler
            ->filter('footer .footer-main a[href^="/"], footer .footer-popular a[href^="/"], footer .footer-bar a[href^="/"]')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertGreaterThan(40, count($hrefs));
        self::assertSame($hrefs, array_values(array_unique($hrefs)));
    }

    public function testFooterSwitchesToTheOtherLanguages(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        $toggle = $crawler->filter('footer .footer-language-toggle');
        self::assertSame('Language: English', $toggle->attr('aria-label'));
        self::assertSame(
            array_map(static fn (string $locale): string => $urlGenerator->generate('faq', ['_locale' => $locale]), ['de', 'cs', 'fr', 'es', 'ja']),
            $crawler->filter('footer .footer-language .dropdown-item')->extract(['href']),
        );
    }

    public function testSubscribedPlayerGetsNoNewsletterElement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $footer = $crawler->filter('footer.site-footer');
        self::assertCount(4, $footer->filter('.footer-columns h2'));
        self::assertCount(0, $footer->filter('.footer-newsletter'));
        self::assertCount(0, $footer->filter('.footer-newsletter-off'));
        self::assertCount(0, $footer->filter('.footer-popular'));
    }

    public function testPlayerWhoSwitchedTheNewsletterOffTurnsItBackOnFromTheFooter(): void
    {
        $browser = $this->playerWithNewsletterOff();

        $crawler = $browser->request('GET', self::PAGE);
        self::assertCount(1, $crawler->filter('footer form.footer-newsletter-off[action="' . self::TURN_ON . '"]'));

        $this->turnOn($browser, 'http://localhost');

        $this->assertResponseRedirects('http://localhost' . self::PAGE);
        self::assertTrue($this->newsletterEnabled($browser));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The monthly news e-mail is on.', $crawler->filter('.alert')->text());
        self::assertCount(0, $crawler->filter('footer .footer-newsletter-off'));
    }

    public function testForgedRequestChangesNothing(): void
    {
        $browser = $this->playerWithNewsletterOff();

        $this->turnOn($browser, 'https://evil.example');

        $this->assertResponseRedirects();
        self::assertFalse($this->newsletterEnabled($browser));
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $this->turnOn($browser, 'http://localhost');

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function playerWithNewsletterOff(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET newsletter_enabled = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    private function turnOn(KernelBrowser $browser, string $origin): void
    {
        // Stateless CSRF: any token, as long as the request comes from our own origin
        $browser->request('POST', self::TURN_ON, ['_token' => 'csrf-token'], server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin . self::PAGE,
        ]);
    }

    private function newsletterEnabled(KernelBrowser $browser): bool
    {
        return (bool) $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT newsletter_enabled FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
    }
}
