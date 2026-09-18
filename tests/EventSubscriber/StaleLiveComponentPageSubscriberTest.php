<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\EventSubscriber\StaleLiveComponentPageSubscriber;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Drives real live component requests through the kernel, with props taken from a
 * rendered page, so the checksum mismatch comes from the library's own hydrator.
 */
final class StaleLiveComponentPageSubscriberTest extends WebTestCase
{
    private const string PAGE = '/en/faq';

    /** Stands in for the key a page was signed with before an APP_SECRET rotation. */
    private const string PREVIOUS_SECRET = 'secret-before-the-rotation';

    public function testPropsFromTheCurrentPageRenderNormally(): void
    {
        $client = self::createClient();
        $props = $this->globalSearchPropsFromPage($client);

        $this->requestGlobalSearch($client, $props);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.live-component+html');
    }

    public function testStaleChecksumReloadsThePageTheVisitorIsOn(): void
    {
        $client = self::createClient();
        $props = $this->resigned($this->globalSearchPropsFromPage($client));

        $client->enableProfiler();
        $this->requestGlobalSearch($client, $props, liveUrl: self::PAGE . '?tab=general');

        // LiveComponentSubscriber turned the redirect into its protocol - the
        // Stimulus controller calls Turbo.visit() with the Location
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertResponseHeaderSame('X-Live-Redirect', '1');
        self::assertResponseHeaderSame('Location', self::PAGE . '?tab=general');

        // Handled before HttpKernel logs it as an uncaught exception (→ Sentry)
        self::assertSame(0, $this->loggedErrors($client));

        $guard = $this->reloadCookie($client);
        self::assertSame(self::PAGE, $guard->getValue());
        self::assertTrue($guard->isHttpOnly());
    }

    public function testStaleChecksumOfPropsFromAParentComponentReloadsToo(): void
    {
        $client = self::createClient();
        $props = $this->globalSearchPropsFromPage($client);

        $this->requestGlobalSearch($client, $props, propsFromParent: ['query' => 'puzzle', '@checksum' => 'c3RhbGU=']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertResponseHeaderSame('Location', self::PAGE);
    }

    public function testFailingAgainRightAfterTheReloadIsLeftToSurfaceAsAnError(): void
    {
        $client = self::createClient();
        $props = $this->resigned($this->globalSearchPropsFromPage($client));

        $this->requestGlobalSearch($client, $props);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The cookie from the first answer is still in the jar: the freshly
        // reloaded page failed too, so this is a bug, not staleness - no loop,
        // and the uncaught exception is logged as an error again (→ Sentry)
        $client->enableProfiler();
        $this->requestGlobalSearch($client, $props);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(1, $this->loggedErrors($client));
    }

    public function testReloadOfAnotherPageDoesNotBlockThisOne(): void
    {
        $client = self::createClient();
        $props = $this->resigned($this->globalSearchPropsFromPage($client));
        $client->getCookieJar()->set(new Cookie(StaleLiveComponentPageSubscriber::RELOAD_COOKIE, '/en/puzzle'));

        $this->requestGlobalSearch($client, $props);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testNeverRedirectsOffSite(): void
    {
        $client = self::createClient();
        $props = $this->resigned($this->globalSearchPropsFromPage($client));

        $this->requestGlobalSearch($client, $props, liveUrl: '//evil.example/en/faq');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMissingChecksumIsNotStaleness(): void
    {
        $client = self::createClient();
        $props = $this->globalSearchPropsFromPage($client);
        unset($props['@checksum']);

        $this->requestGlobalSearch($client, $props);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRequestNotSentByTheLiveControllerKeepsThePlainError(): void
    {
        $client = self::createClient();
        $props = $this->resigned($this->globalSearchPropsFromPage($client));

        $this->requestGlobalSearch($client, $props, sentByLiveController: false);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return array<string, mixed>
     */
    private function globalSearchPropsFromPage(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        $propsJson = $crawler->filter('[data-live-name-value="GlobalSearch"]')->attr('data-live-props-value');
        self::assertIsString($propsJson);

        $props = json_decode($propsJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertArrayHasKey('@checksum', $props);

        /** @var array<string, mixed> $props */
        return $props;
    }

    /**
     * The same props, signed the way the library signs them but with another key -
     * what a page rendered before an APP_SECRET rotation still carries.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function resigned(array $props): array
    {
        unset($props['@checksum']);
        $signed = $props;
        self::sortKeysRecursively($signed);

        $props['@checksum'] = base64_encode(hash_hmac(
            'sha256',
            "GlobalSearch\0props\0" . json_encode($signed, JSON_THROW_ON_ERROR),
            self::PREVIOUS_SECRET,
            true,
        ));

        return $props;
    }

    /**
     * @param array<mixed> $data
     */
    private static function sortKeysRecursively(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::sortKeysRecursively($value);
            }
        }

        ksort($data);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $propsFromParent
     */
    private function requestGlobalSearch(
        KernelBrowser $client,
        array $props,
        string $liveUrl = self::PAGE,
        array $propsFromParent = [],
        bool $sentByLiveController = true,
    ): void {
        $server = ['HTTP_X_LIVE_URL' => $liveUrl];

        if ($sentByLiveController) {
            $server['HTTP_ACCEPT'] = 'application/vnd.live-component+html';
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        $client->request('POST', '/en/_components/GlobalSearch', [
            'data' => json_encode([
                'props' => $props,
                'updated' => ['query' => 'puzzle'],
                'propsFromParent' => $propsFromParent,
            ], JSON_THROW_ON_ERROR),
        ], server: $server);
    }

    private function loggedErrors(KernelBrowser $client): int
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('logger');
        self::assertInstanceOf(LoggerDataCollector::class, $collector);

        return $collector->countErrors();
    }

    private function reloadCookie(KernelBrowser $client): Cookie
    {
        $cookie = $client->getCookieJar()->get(StaleLiveComponentPageSubscriber::RELOAD_COOKIE);
        self::assertNotNull($cookie, 'The reload guard cookie was not set');

        return $cookie;
    }
}
