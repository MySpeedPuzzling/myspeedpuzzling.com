<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The endpoints table on the "For developers" page lists every /api/v1 route, one row per
 * method + path. It went stale once (competitions, series, favorites/followers and the
 * collection items were missing), so a route added or removed without the table fails here.
 * Parameter names do not matter: {playerId} in the route matches {id} in the table.
 */
final class ForDevelopersEndpointsTableTest extends WebTestCase
{
    public function testTheTableListsEveryApiV1Route(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/for-developers');

        $this->assertResponseIsSuccessful();

        $listed = $crawler->filter('[data-api-endpoint]')->each(
            static fn (Crawler $code): string => self::normalize(trim($code->text())),
        );

        $router = self::getContainer()->get(RouterInterface::class);

        $routes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = str_replace('.{_format}', '', $route->getPath());

            if ($path !== '/api/v1' && !str_starts_with($path, '/api/v1/')) {
                continue;
            }

            self::assertNotSame([], $route->getMethods(), sprintf('API route "%s" should name its HTTP method.', $name));

            foreach ($route->getMethods() as $method) {
                $routes[] = self::normalize($method . ' ' . $path);
            }
        }

        sort($listed);
        sort($routes);

        self::assertNotSame([], $routes);
        self::assertSame(
            $routes,
            $listed,
            'templates/for-developers.html.twig must list every /api/v1 route exactly once - add or remove the row.',
        );
    }

    private static function normalize(string $endpoint): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $endpoint);
    }
}
