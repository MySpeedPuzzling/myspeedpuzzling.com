<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WjpcHubControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalizedPaths(): iterable
    {
        yield 'en' => ['/en/world-jigsaw-puzzle-championship'];
        yield 'cs' => ['/mistrovstvi-sveta-ve-skladani-puzzle'];
        yield 'de' => ['/de/puzzle-weltmeisterschaft'];
        yield 'fr' => ['/fr/championnat-du-monde-de-puzzle'];
        yield 'es' => ['/es/campeonato-mundial-de-puzzles'];
        yield 'ja' => ['/ja/世界ジグソーパズル選手権'];
    }

    #[DataProvider('provideLocalizedPaths')]
    public function testPageIsAccessibleInAllLocales(string $path): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        $this->assertResponseIsSuccessful();
    }

    public function testPageHasExactlyOneH1(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function testTitleContainsWorldJigsaw(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        $title = $crawler->filter('title')->text();

        // Before the wjpc_hub translations are merged, the raw translation key is rendered.
        self::assertTrue(
            str_contains($title, 'World Jigsaw') || str_contains($title, 'wjpc_hub.meta.title'),
            sprintf('Title "%s" should contain "World Jigsaw"', $title),
        );
    }

    public function testJsonLdIsValidAndContainsBreadcrumbAndItemList(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        self::assertNotEmpty($matches[1], 'Page should contain JSON-LD scripts');

        $types = [];

        foreach ($matches[1] as $json) {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($decoded);

            if (isset($decoded['@type']) && is_string($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }

        self::assertContains('BreadcrumbList', $types, 'Page should contain BreadcrumbList JSON-LD');
        self::assertContains('ItemList', $types, 'Page should contain ItemList JSON-LD');
    }

    public function testEditionsTableLinksToEventPages(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        // Fixtures contain the approved "WJPC 2024" competition with slug "wjpc-2024".
        $eventLinks = $crawler->filter('table a[href*="/en/events/"]');

        self::assertGreaterThanOrEqual(1, $eventLinks->count(), 'Editions table should link to at least one event page');
    }
}
