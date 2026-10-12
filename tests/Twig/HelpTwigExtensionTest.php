<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Twig\HelpTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Help sheet's openers (implementation plan §7): help_path() carries the current page as a relative ?url= (bare on
 * the help page itself), help_opener_attrs() opens the sheet in modal-frame on the web - a plain visit in the app.
 */
final class HelpTwigExtensionTest extends KernelTestCase
{
    public function testHelpPathCarriesTheCurrentPage(): void
    {
        $this->pushRequest('/en/puzzle/abc?tab=times', 'puzzle_detail');

        $helpPath = $this->extension()->helpPath();
        parse_str((string) parse_url($helpPath, PHP_URL_QUERY), $query);

        self::assertSame('/en/help', parse_url($helpPath, PHP_URL_PATH));
        self::assertSame(['url' => '/en/puzzle/abc?tab=times'], $query);
    }

    public function testHelpPathIsBareOnTheHelpPage(): void
    {
        $this->pushRequest('/en/help?url=%2Fen%2Ffaq', 'help');

        self::assertSame('/en/help', $this->extension()->helpPath());
    }

    public function testOpenersAreSheetsOnTheWebAndVisitsInTheApp(): void
    {
        $this->pushRequest('/en/faq', 'faq');
        self::assertSame('data-turbo-frame="modal-frame" data-modal-scrim="own" rel="nofollow"', $this->extension()->helpOpenerAttributes());

        $this->pushRequest('/en/faq', 'faq', NativeUserAgents::IOS);
        self::assertSame('data-turbo-frame="_top" data-modal-scrim="own" rel="nofollow"', $this->extension()->helpOpenerAttributes());
    }

    private function pushRequest(string $uri, string $route, null|string $userAgent = null): void
    {
        $request = Request::create('http://localhost' . $uri, server: $userAgent !== null ? ['HTTP_USER_AGENT' => $userAgent] : []);
        $request->attributes->set('_route', $route . '.en');
        $request->attributes->set('_canonical_route', $route);
        $request->attributes->set('_locale', 'en');

        $requestStack = self::getContainer()->get(RequestStack::class);

        while ($requestStack->pop() !== null) {
            // Each case starts with its own main request
        }

        $requestStack->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->setParameter('_locale', 'en');
    }

    private function extension(): HelpTwigExtension
    {
        return self::getContainer()->get(HelpTwigExtension::class);
    }
}
