<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared helpers for the sitemap index and its child sitemaps.
 *
 * Every logical page is emitted once per locale (6 <url> entries) with no
 * xhtml:link alternates - hreflang lives exclusively in the page <head>.
 *
 * @phpstan-type SitemapEntry array{loc: string, lastmod: null|string}
 */
trait SitemapResponseTrait
{
    /**
     * @var list<string>
     */
    private const array SITEMAP_LOCALES = ['cs', 'en', 'es', 'ja', 'fr', 'de'];

    /**
     * @param array<string, int|string> $parameters
     * @return list<array{loc: string, lastmod: null|string}>
     */
    private function localizedEntries(string $route, array $parameters = [], null|string $lastmod = null): array
    {
        $entries = [];

        foreach (self::SITEMAP_LOCALES as $locale) {
            $entries[] = [
                'loc' => $this->generateUrl($route, ['_locale' => $locale] + $parameters, UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $lastmod,
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{loc: string, lastmod: null|string}> $entries
     */
    private function urlsetResponse(array $entries): Response
    {
        return $this->xmlResponse('sitemap_urlset.xml.twig', [
            'entries' => $entries,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function xmlResponse(string $template, array $context): Response
    {
        $response = new Response(headers: [
            'Content-Type' => 'text/xml',
        ]);

        // Sitemaps do not need real-time updates - let shared caches hold
        // them for 6 hours (browsers for 1 hour).
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(21600);
        // Same content for every visitor: stop the session listener from
        // downgrading the response to private/no-cache.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $this->render($template, $context, $response);
    }
}
