<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Twig\PostActionTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The one-tap links of the browse pages (docs/features/navigation GEST-15, decision 99; Instant Back review F8, F9):
 *
 * - `back` - where the confirmation view's Cancel leads - is the page the link is on: the request's own address, and
 *   when a Live component re-renders the link (its own request, `/_components/<Name>`) the page Live names in X-Live-Url,
 *   else the Referer's path - each only when ReturnUrl accepts it; never the component's endpoint
 * - the links were buttons: role="button" on every one; Block (it asks first, in the modal frame) carries the controller
 *   for Space only - no URL to post to
 */
final class PostActionTwigExtensionTest extends KernelTestCase
{
    private const array WISHLIST = ['wishlist_remove', ['puzzleId' => PuzzleFixture::PUZZLE_4000], ['context' => 'detail']];

    public function testBackIsThePageTheLinkIsOn(): void
    {
        $this->pushMainRequest(Request::create('http://localhost/en/puzzle?search=sea'));

        self::assertSame('/en/puzzle?search=sea', $this->back());
    }

    public function testALiveReRenderPointsBackToThePageLiveNames(): void
    {
        $request = $this->liveRequest(['X-Live-Url' => '/en/puzzle?search=sea', 'Referer' => 'http://localhost/en/hub']);
        $this->pushMainRequest($request);

        self::assertSame('/en/puzzle?search=sea', $this->back());
    }

    public function testALiveReRenderWithoutItsHeaderPointsBackToTheReferer(): void
    {
        $this->pushMainRequest($this->liveRequest(['Referer' => 'http://localhost/en/puzzle/brands?letter=r']));

        self::assertSame('/en/puzzle/brands?letter=r', $this->back());
    }

    public function testALiveReRenderNeverPointsBackElsewhere(): void
    {
        // A hostile header and a referer of another site: no back at all (the view's Cancel goes home)
        $this->pushMainRequest($this->liveRequest(['X-Live-Url' => '//evil.example/x', 'Referer' => 'https://evil.example/en/puzzle']));
        self::assertNull($this->back());

        $this->pushMainRequest($this->liveRequest([]));
        self::assertNull($this->back());

        // A GET re-render (props in its query): never the endpoint, never its query
        $get = Request::create('http://localhost/en/_components/PuzzleSearch?props=%7B%22query%22%3A%22sea%22%7D');
        $get->headers->set('X-Live-Url', '/en/puzzle');
        $this->pushMainRequest($get);
        self::assertSame('/en/puzzle', $this->back());
    }

    public function testEveryLinkIsAButton(): void
    {
        $this->pushMainRequest(Request::create('http://localhost/en/puzzle'));
        $extension = self::getContainer()->get(PostActionTwigExtension::class);

        $oneTap = $extension->attributes(...self::WISHLIST);
        self::assertStringContainsString(' rel="nofollow" role="button" ', $oneTap);
        self::assertStringContainsString('data-controller="post-action"', $oneTap);
        self::assertStringContainsString('data-post-action-url-value="', $oneTap);

        $block = $extension->attributes('block_player', ['playerId' => '018d0000-0000-0000-0000-000000000001']);
        self::assertStringContainsString(' rel="nofollow" role="button" ', $block);
        self::assertStringContainsString('data-turbo-frame="modal-frame"', $block);
        // Space only: Turbo opens the confirmation view, nothing is posted from the page
        self::assertStringContainsString('data-controller="post-action"', $block);
        self::assertStringNotContainsString('data-post-action-url-value', $block);
    }

    /**
     * @param array<string, string> $headers
     */
    private function liveRequest(array $headers): Request
    {
        $request = Request::create('http://localhost/en/_components/PuzzleSearch', 'POST');
        $request->attributes->set('_live_component', 'PuzzleSearch');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function back(): null|string
    {
        $url = self::getContainer()->get(PostActionTwigExtension::class)->confirmUrl(...self::WISHLIST);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('/en/action/wishlist_remove', parse_url($url, PHP_URL_PATH));
        $back = $query['back'] ?? null;
        self::assertTrue($back === null || is_string($back));

        return $back;
    }

    private function pushMainRequest(Request $request): void
    {
        $request->attributes->set('_locale', 'en');
        $requestStack = self::getContainer()->get(RequestStack::class);

        while ($requestStack->pop() !== null) {
            // Each case starts with its own main request
        }

        $requestStack->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->setParameter('_locale', 'en');
    }
}
