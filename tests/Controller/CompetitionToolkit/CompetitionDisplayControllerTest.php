<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The display page `GET /display/{token}` (D-displays-overlays.md §7.1, competition toolkit D4): a standalone page by
 * the token alone - no site chrome, no service worker, no Turbo bundle, never cached or indexed, no Referer, no cookie -
 * with the design and the first state inline for assets/display.js.
 */
final class CompetitionDisplayControllerTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;

    public function testTheDisplayPageIsStandaloneNeverCachedAndSetsNoCookie(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [['type' => 'timer'], ['type' => 'qr', 'props' => ['target' => 'event_page']]], OfficialResultsFixture::ROUND_GROUP_A);

        // A signed-in organiser's session changes nothing: the stateless firewall never reads it (the second request
        // is what a browser gets - see CompetitionDisplayStateControllerTest)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/display/' . $token);
        $crawler = $browser->request('GET', '/display/' . $token);

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame([], $response->headers->getCookies());

        $html = (string) $response->getContent();
        self::assertStringStartsWith('<!doctype html>', ltrim(preg_replace('/\{#.*?#\}/s', '', $html) ?? $html));
        self::assertSame('noindex,nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('no-referrer', $crawler->filter('meta[name="referrer"]')->attr('content'));

        // Nothing of the site: no service worker registration, no self-heal scripts, no app bundle, no header/footer
        foreach (['service-worker', 'serviceWorker', 'msp-rendered-at', '/build/app.js', '<header', '<footer', 'asset-load-failure'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        self::assertStringContainsString('/build/display.js', $html);

        $root = $crawler->filter('#msp-display');
        self::assertSame('screen', $root->attr('data-kind'));
        self::assertSame('/display/' . $token . '/state', $root->attr('data-state-url'));
        self::assertSame('/display/' . $token . '/design', $root->attr('data-design-url'));

        $state = self::json($root, 'data-state');
        $design = self::json($root, 'data-design');
        self::assertSame('Results Cup', self::at($state, 'competition.name'));
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, self::at($state, 'round.id'));
        self::assertIsInt($state['serverNowMs']);
        self::assertSame(1, $design['schema']);
        self::assertCount(2, (array) $design['elements']);

        // The QR element's code is in the state (modules, the renderer draws them), pointing at the event page
        $qr = self::at($state, 'qr.el000001');
        self::assertIsArray($qr);
        self::assertIsInt($qr['size']);
        self::assertIsArray($qr['rows']);
        self::assertCount($qr['size'], $qr['rows']);
        self::assertIsString($qr['rows'][0]);
        self::assertMatchesRegularExpression('/^[01]+$/', $qr['rows'][0]);
        self::assertSame('This display link is no longer valid.', self::at($state, 'texts.invalid'));
    }

    public function testAnOverlayHasATransparentPage(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [['type' => 'timer']], kind: DisplayKind::Overlay);

        $crawler = $browser->request('GET', '/display/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSame('overlay', $crawler->filter('#msp-display')->attr('data-kind'));
        self::assertStringContainsString('html,body{background:transparent!important', (string) $browser->getResponse()->getContent());
    }

    public function testAnUnknownOrRegeneratedLinkIsAStandalone404(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_display SET token = 'NewToken1NewToken1NewT' WHERE token = :token",
            ['token' => $token],
        );

        foreach (['/display/' . $token, '/display/' . str_repeat('A', 22), '/display/short'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
            $html = (string) $browser->getResponse()->getContent();
            self::assertStringContainsString('data-display-not-found', $html, $url);
            self::assertStringNotContainsString('service-worker', $html, $url);
            self::assertStringNotContainsString('<footer', $html, $url);
            // (a malformed token is outside the stateless firewall's pattern - the main firewall adds its own directives)
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * The page costs the state's statements (display + event, rounds, visible puzzles) - nothing more.
     */
    public function testThePageCostsTheStatesStatements(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/display/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->queryCount($browser));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Crawler $root, string $attribute): array
    {
        $decoded = json_decode((string) $root->attr($attribute), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
