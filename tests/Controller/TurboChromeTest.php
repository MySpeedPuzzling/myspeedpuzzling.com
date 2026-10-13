<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Turbo visits without layout shift (docs/features/navigation README §4.3 "State", implementation plan NAV-07): the top
 * bar, the tab bar / rail, the column and the Menu shell are re-rendered with every visit - never data-turbo-permanent -
 * their sizes reserved by the critical CSS. Everything the client decides lives on <html> (the head scripts'
 * data-os / data-nav-layout / data-nav-collapsed / is-standalone, the top bar's data-topbar), which a visit keeps -
 * Turbo copies only lang and dir - so the server puts no state there but lang (and data-native-app inside the app,
 * which never changes within a session). The head scripts and the critical CSS have the same text on every page and
 * for every viewer: Turbo runs a head script again, and appends a <style>, only when its text differs from the
 * current page's.
 */
final class TurboChromeTest extends WebTestCase
{
    private const string CHROME = 'body > header.site-header, #bottom-nav, #menu-slot, #menu-layer';

    public function testTheChromeIsNeverPermanentAndHtmlCarriesOnlyTheLanguage(): void
    {
        $browser = self::createClient();

        foreach ($this->pages() as $label => $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($label);
            $this->assertChromeIsRenderedPerVisit($crawler, 'guest ' . $label);
            self::assertSame(['lang' => 'en'], $this->htmlAttributes($crawler), 'guest ' . $label);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ($this->pages() + ['own profile' => '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE] as $label => $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($label);
            $this->assertChromeIsRenderedPerVisit($crawler, 'member ' . $label);
            self::assertSame(['lang' => 'en'], $this->htmlAttributes($crawler), 'member ' . $label);
        }

        // Inside the app: data-native-app (fixed for the session) and no chrome at all
        $browser->setServerParameter('HTTP_USER_AGENT', NativeUserAgents::IOS);
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertSame(['lang' => 'en', 'data-native-app' => 'ios'], $this->htmlAttributes($crawler));
        self::assertCount(0, $crawler->filter(self::CHROME));
    }

    public function testHeadScriptsAndCriticalCssReadTheSameOnEveryPage(): void
    {
        $browser = self::createClient();
        $heads = [];

        foreach ($this->pages() as $label => $url) {
            $heads['guest ' . $label] = $this->head($browser, $url);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ($this->pages() + ['own profile' => '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE] as $label => $url) {
            $heads['member ' . $label] = $this->head($browser, $url);
        }

        $first = reset($heads);

        // templates/_head_flags.html.twig: platform_os, standalone, nav_layout - the scripts that set <html>'s client
        // attributes before first paint
        self::assertCount(3, $first['flags']);
        self::assertStringContainsString("getItem('msp:nav-layout')", $first['flags'][2]);

        foreach ($heads as $label => $head) {
            self::assertSame($first['flags'], $head['flags'], $label . ': the head flag scripts');
            self::assertSame($first['critical'], $head['critical'], $label . ': the critical CSS');
        }
    }

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        return [
            'Hub' => '/en/hub',
            'puzzle page' => '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
            'FAQ' => '/en/faq',
        ];
    }

    private function assertChromeIsRenderedPerVisit(Crawler $crawler, string $label): void
    {
        self::assertCount(1, $crawler->filter('body > header.site-header'), $label);
        self::assertCount(1, $crawler->filter('body > #bottom-nav'), $label);
        self::assertCount(1, $crawler->filter('#site-menu'), $label);

        foreach ($crawler->filter(self::CHROME) as $root) {
            assert($root instanceof DOMElement);
            self::assertFalse($root->hasAttribute('data-turbo-permanent'), $label . ': ' . $root->getAttribute('class'));
        }

        self::assertCount(0, $crawler->filter('body > header.site-header [data-turbo-permanent], #bottom-nav [data-turbo-permanent], #menu-slot [data-turbo-permanent], #menu-layer [data-turbo-permanent]'), $label);
    }

    /**
     * @return array<string, string>
     */
    private function htmlAttributes(Crawler $crawler): array
    {
        $html = $crawler->filter('html')->getNode(0);
        assert($html instanceof DOMElement);
        $attributes = [];

        foreach ($html->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }

    /**
     * @return array{flags: list<string>, critical: string}
     */
    private function head(KernelBrowser $browser, string $url): array
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        // The head flag scripts follow the viewport meta (implementation plan §3.11)
        $flags = [];

        foreach ($crawler->filter('head > meta[name="viewport"] ~ script') as $script) {
            assert($script instanceof DOMElement);

            if ($script->hasAttribute('src') || $script->hasAttribute('type') || !str_contains($script->textContent, 'documentElement')) {
                continue;
            }

            if (str_contains($script->textContent, "'data-os'") || str_contains($script->textContent, 'is-standalone') || str_contains($script->textContent, 'msp:nav-layout')) {
                $flags[] = $script->textContent;
            }
        }

        $critical = $crawler->filter('head > style')->reduce(static fn (Crawler $style): bool => str_contains($style->text(), '--nav-left'));
        self::assertCount(1, $critical, $url);

        return ['flags' => $flags, 'critical' => (string) $critical->getNode(0)?->textContent];
    }
}
