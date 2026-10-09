<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapStaticControllerTest extends WebTestCase
{
    private const array LOCALES = ['cs', 'en', 'es', 'ja', 'fr', 'de'];

    public function testNoindexHubIsLeftOutInEveryLocale(): void
    {
        $content = $this->fetchStaticSitemap();

        foreach (self::LOCALES as $locale) {
            self::assertStringNotContainsString(
                sprintf('<loc>%s</loc>', $this->absoluteUrl('hub', $locale)),
                $content,
                sprintf('The %s hub is noindex and must not be submitted', $locale),
            );
        }
    }

    public function testIndexablePagesAreStillListedInEveryLocale(): void
    {
        $content = $this->fetchStaticSitemap();

        foreach (self::LOCALES as $locale) {
            foreach (['homepage', 'ladder', 'recent_activity', 'puzzle_tracker_app', 'organizations'] as $route) {
                self::assertStringContainsString(
                    sprintf('<loc>%s</loc>', $this->absoluteUrl($route, $locale)),
                    $content,
                    sprintf('%s (%s)', $route, $locale),
                );
            }
        }
    }

    private function fetchStaticSitemap(): string
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');

        $this->assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function absoluteUrl(string $route, string $locale): string
    {
        return self::getContainer()->get(UrlGeneratorInterface::class)
            ->generate($route, ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
