<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Twig\HelpTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Help sheet's openers (implementation plan §7): help_path() carries the current page as a relative ?url= (bare on
 * the help page itself; a private link page's token-free path, HELP review Q2), help_opener_attrs() opens the sheet in
 * modal-frame on the web - a plain visit in the app.
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
        // data-help-opener="page": assets/help_openers.js sets ?url= to the page as it is at the tap (HELP review F4)
        $this->pushRequest('/en/faq', 'faq');
        self::assertSame('data-turbo-frame="modal-frame" data-modal-scrim="own" rel="nofollow" data-help-opener="page"', $this->extension()->helpOpenerAttributes());

        $this->pushRequest('/en/faq', 'faq', NativeUserAgents::IOS);
        self::assertSame('data-turbo-frame="_top" data-modal-scrim="own" rel="nofollow" data-help-opener="page"', $this->extension()->helpOpenerAttributes());
    }

    /**
     * HELP review Q2: a page whose URL holds a private token never hands it to the Help sheet - the opener attaches the
     * route's token-free path and keeps it at the tap (data-help-opener="private")
     *
     * @return iterable<string, array{string, string, null|string, string}>
     */
    public static function providePrivateLinkPages(): iterable
    {
        // uri, canonical route, the localized variant (null = not localized), the path attached
        yield 'delete account' => ['/delete-account/S3cretT0kenValue', 'confirm_account_deletion', null, '/delete-account/{token}'];
        yield 'e-mail preferences' => ['/en/email-preferences/S3cretT0kenValue', 'email_preferences', 'email_preferences.en', '/en/email-preferences/{token}'];
        yield 'e-mail preferences re-rendered at its POST URL' => ['/en/email-preferences/S3cretT0kenValue/save', 'email_preferences_save', 'email_preferences_save.en', '/en/email-preferences/{token}/save'];
        // The router's match names a localized route by its canonical name
        yield 'newsletter confirm' => ['/en/newsletter/confirm/S3cretT0kenValue', 'newsletter_confirm', 'newsletter_confirm', '/en/newsletter/confirm/{token}'];
        yield 'a signed unsubscribe link' => ['/en/activity-digest/unsubscribe/018d0000-0000-0000-0000-000000000001?_hash=S3cretT0kenValue', 'content_digest_unsubscribe', null, '/en/activity-digest/unsubscribe/{playerId}'];
    }

    #[DataProvider('providePrivateLinkPages')]
    public function testAPrivateLinkPageAttachesItsTokenFreePath(string $uri, string $route, null|string $variant, string $attached): void
    {
        $this->pushRequest($uri, $route, variant: $variant ?? $route);

        $helpPath = $this->extension()->helpPath();
        parse_str((string) parse_url($helpPath, PHP_URL_QUERY), $query);

        self::assertSame(['url' => $attached], $query);
        self::assertStringNotContainsString('S3cretT0kenValue', $helpPath);
        self::assertStringContainsString('data-help-opener="private"', $this->extension()->helpOpenerAttributes());
    }

    private function pushRequest(string $uri, string $route, null|string $userAgent = null, null|string $variant = null): void
    {
        $request = Request::create('http://localhost' . $uri, server: $userAgent !== null ? ['HTTP_USER_AGENT' => $userAgent] : []);
        $request->attributes->set('_route', $variant ?? $route . '.en');
        $request->attributes->set('_canonical_route', $route);
        $request->attributes->set('_locale', 'en');
        $request->setLocale('en');

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
