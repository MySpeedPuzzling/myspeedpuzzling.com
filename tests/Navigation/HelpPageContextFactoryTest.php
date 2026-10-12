<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Help sheet's ?url= is untrusted (implementation plan §7, HELP-01a): only a same-site path, or an absolute URL on
 * the request's own host, that the router matches becomes the page context - anything else is the empty context.
 */
final class HelpPageContextFactoryTest extends KernelTestCase
{
    private const string PUZZLE = PuzzleFixture::PUZZLE_500_01;

    /**
     * @return iterable<string, array{string, string, string, null|string}>
     */
    public static function provideAcceptedUrls(): iterable
    {
        // url, the path kept, the route, the puzzle id
        yield 'relative path' => ['/en/faq', '/en/faq', 'faq', null];
        yield 'relative path with a query' => ['/en/ladder?country=cz', '/en/ladder?country=cz', 'ladder', null];
        yield 'a puzzle page' => ['/en/puzzle/' . self::PUZZLE, '/en/puzzle/' . self::PUZZLE, 'puzzle_detail', self::PUZZLE];
        yield 'a Czech puzzle page' => ['/puzzle/' . self::PUZZLE, '/puzzle/' . self::PUZZLE, 'puzzle_detail', self::PUZZLE];
        // Old feedback links carried app.request.uri
        yield 'absolute URL on the same host' => ['http://localhost/en/puzzle/' . self::PUZZLE . '?tab=times', '/en/puzzle/' . self::PUZZLE . '?tab=times', 'puzzle_detail', self::PUZZLE];
        yield 'same host, other scheme and case' => ['HTTPS://LocalHost/en/faq', '/en/faq', 'faq', null];
        yield 'ja path, raw' => ['/ja/パズル/' . self::PUZZLE, '/ja/パズル/' . self::PUZZLE, 'puzzle_detail', self::PUZZLE];
        yield 'ja path, percent-encoded' => ['/ja/' . rawurlencode('パズル') . '/' . self::PUZZLE, '/ja/' . rawurlencode('パズル') . '/' . self::PUZZLE, 'puzzle_detail', self::PUZZLE];
        // The route has no UUID requirement: a malformed id is a page, but never a puzzle
        yield 'a puzzle page with a malformed id' => ['/en/puzzle/not-a-uuid', '/en/puzzle/not-a-uuid', 'puzzle_detail', null];
    }

    #[DataProvider('provideAcceptedUrls')]
    public function testAcceptedUrls(string $url, string $path, string $route, null|string $puzzleId): void
    {
        $context = $this->factory()->fromUrlParameter($url, Request::create('http://localhost/en/help'));

        self::assertFalse($context->isEmpty());
        self::assertSame($path, $context->path);
        self::assertSame($route, $context->routeName);
        self::assertSame($puzzleId, $context->puzzleId);

        foreach (array_keys($context->routeParams) as $name) {
            self::assertStringStartsNotWith('_', (string) $name);
        }
    }

    /**
     * The feedback box posts the page back (HELP-01b): a GET-only route still matches while the request is a POST, and
     * the router's context keeps the request's method afterwards
     */
    public function testAPageIsMatchedAsAGetFromAPost(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $router->getContext()->setMethod('POST');

        $context = $this->factory()->fromUrlParameter('/en/menu', Request::create('http://localhost/en/feedback', 'POST'));

        self::assertSame('menu', $context->routeName);
        self::assertSame('/en/menu', $context->path);
        self::assertSame('POST', $router->getContext()->getMethod());
    }

    public function testATrailingSlashKeepsOnlyTheRoutesOwnParameters(): void
    {
        // Matched through Symfony's RedirectController, whose path/permanent/scheme/ports attributes are no route parameters
        $context = $this->factory()->fromUrlParameter('/en/faq/', Request::create('http://localhost/en/help'));

        self::assertSame('faq', $context->routeName);
        self::assertSame([], $context->routeParams);

        $puzzle = $this->factory()->fromUrlParameter('/en/puzzle/' . self::PUZZLE . '/', Request::create('http://localhost/en/help'));

        if (!$puzzle->isEmpty()) {
            self::assertSame(['puzzleId' => self::PUZZLE], array_intersect_key($puzzle->routeParams, ['puzzleId' => true]));
            self::assertSame([], array_diff(array_keys($puzzle->routeParams), ['puzzleId', '_locale']));
        }
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideRefusedUrls(): iterable
    {
        yield 'nothing' => [null];
        yield 'empty' => [''];
        yield 'foreign host' => ['https://evil.example/en/faq'];
        yield 'foreign host with our host as a subdomain' => ['https://localhost.evil.example/en/faq'];
        yield 'credentials before our host' => ['https://localhost@evil.example/en/faq'];
        yield 'user info on our host' => ['https://user:secret@localhost/en/faq'];
        yield 'scheme-relative' => ['//evil.example/en/faq'];
        yield 'backslash' => ['/\\evil.example/en/faq'];
        yield 'encoded scheme-relative' => ['%2F%2Fevil.example%2Fen%2Ffaq'];
        yield 'double-encoded scheme-relative' => ['/%252F%252Fevil.example'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'relative without a slash' => ['en/faq'];
        yield 'control character' => ["/en/faq\r\nX-Header: 1"];
        yield 'no route' => ['/en/no-such-page-anywhere'];
        yield 'ftp on our host' => ['ftp://localhost/en/faq'];
    }

    #[DataProvider('provideRefusedUrls')]
    public function testRefusedUrlsGiveTheEmptyContext(null|string $url): void
    {
        $context = $this->factory()->fromUrlParameter($url, Request::create('http://localhost/en/help'));

        self::assertTrue($context->isEmpty());
        self::assertNull($context->path);
        self::assertNull($context->routeName);
        self::assertSame([], $context->routeParams);
        self::assertNull($context->puzzleId);
    }

    private function factory(): HelpPageContextFactory
    {
        return self::getContainer()->get(HelpPageContextFactory::class);
    }
}
