<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use DOMElement;
use ReflectionClass;
use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * HELP review Q2: a page whose URL holds a private token - delete-account, e-mail preferences, the newsletter links, the
 * signed unsubscribe links, … - never hands that token to the Help sheet, its box or the feedback e-mail: the help
 * context knows it by its token-free path only (HelpPageContextFactory::PRIVATE_LINK_ROUTES, one list).
 *
 * A route is a token route when a path variable is named …token / …hash / …signature / …secret, or its controller checks
 * a signed URL (UriSigner) or reads a secret from the query (token, hash, code, signature). Every one must be listed - a
 * new token page fails here until it is. JSON endpoints (/api/, /internal-api/) and framework routes (/_…) render no
 * Help opener.
 */
final class HelpPrivateLinkRoutesTest extends WebTestCase
{
    private const string SECRET_VARIABLE = '/(token|hash|signature|secret)$/i';
    private const string SECRET_IN_CONTROLLER = '/UriSigner|->query->(?:get|getString)\(\s*\'(?:token|hash|_hash|code|signature|secret)\'/';

    public function testEveryTokenRouteIsAPrivateLink(): void
    {
        $missing = [];

        foreach ($this->tokenRoutes() as $name => $reason) {
            if (!in_array($name, HelpPageContextFactory::PRIVATE_LINK_ROUTES, true)) {
                $missing[] = $name . ' (' . $reason . ')';
            }
        }

        self::assertSame([], $missing, 'Token routes missing in HelpPageContextFactory::PRIVATE_LINK_ROUTES');
    }

    public function testEveryListedRouteExists(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $unknown = [];

        foreach (HelpPageContextFactory::PRIVATE_LINK_ROUTES as $name) {
            if ($routes->get($name) === null && $routes->get($name . '.en') === null) {
                $unknown[] = $name;
            }
        }

        self::assertSame([], $unknown);
    }

    /**
     * The rendered page (a newsletter link with a token the server does not know: its own page on the base layout,
     * 404): every Help opener attaches the token-free path, keeps it at the tap, and nowhere carries the token
     */
    public function testATokenPageNeverHandsItsTokenToAHelpOpener(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/newsletter/confirm/S3cretT0kenValue');

        self::assertResponseStatusCodeSame(404);
        $openers = $crawler->filter('a[data-help-opener]');
        self::assertGreaterThanOrEqual(2, $openers->count(), 'the top bar ? and the end-of-page line');

        foreach ($openers as $opener) {
            assert($opener instanceof DOMElement);
            $href = $opener->getAttribute('href');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            self::assertStringNotContainsString('S3cretT0kenValue', $href);
            self::assertSame(['url' => '/en/newsletter/confirm/{token}'], $query);
            self::assertSame('private', $opener->getAttribute('data-help-opener'));
        }
    }

    /**
     * @return array<string, string> canonical route name => why it is a token route
     */
    private function tokenRoutes(): array
    {
        self::bootKernel();
        $tokenRoutes = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (str_starts_with($path, '/api/') || str_starts_with($path, '/internal-api/') || str_starts_with($path, '/_')) {
                continue;
            }

            $canonical = $route->getDefault('_canonical_route');
            $canonical = is_string($canonical) ? $canonical : $name;

            foreach (array_filter($route->compile()->getPathVariables(), is_string(...)) as $variable) {
                if (preg_match(self::SECRET_VARIABLE, $variable) === 1) {
                    $tokenRoutes[$canonical] = 'path variable {' . $variable . '}';
                }
            }

            $controller = $route->getDefault('_controller');

            if (!is_string($controller) || isset($tokenRoutes[$canonical])) {
                continue;
            }

            $class = explode('::', $controller)[0];

            if (!class_exists($class)) {
                continue;
            }

            $file = new ReflectionClass($class)->getFileName();

            if (is_string($file) && preg_match(self::SECRET_IN_CONTROLLER, (string) file_get_contents($file)) === 1) {
                $tokenRoutes[$canonical] = 'signed URL or a secret in the query, ' . $class;
            }
        }

        // The rule must still find what it was written for
        self::assertArrayHasKey('confirm_account_deletion', $tokenRoutes);
        self::assertArrayHasKey('result_emails_unsubscribe', $tokenRoutes);
        self::assertArrayHasKey('claim_voucher', $tokenRoutes);

        return $tokenRoutes;
    }
}
