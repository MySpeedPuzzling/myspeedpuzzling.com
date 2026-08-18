<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Services\Wjpf\WjpfPairingCodeStore;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The manual pairing flow: WJPF sends a member here, we confirm who they are, and hand them
 * back with a single-use code.
 */
final class ConnectWjpfControllerTest extends WebTestCase
{
    private const string PATH = '/connect/wjpf';
    private const string STATE = 'wjpfState123';

    public function testAnonymousVisitorIsSentToSignIn(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::PATH . '?state=' . self::STATE);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }

    public function testSignedInMemberSeesTheConsentPage(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', self::PATH . '?state=' . self::STATE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="state"][value="' . self::STATE . '"]'));
    }

    /** A state we would refuse to echo must not be smuggled through the form either. */
    public function testHostileStateIsDroppedFromTheForm(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', self::PATH . '?state=' . urlencode('abc&code=stolen'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="state"]'));
    }

    public function testConfirmingRedirectsToWjpfWithARedeemableCode(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', self::PATH . '?state=' . self::STATE);
        $browser->submit($crawler->filter('button[value="connect"]')->form());

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringStartsWith('https://worldjigsawpuzzle.org/users/users_pr.php?accion=msp_pair_redirect&', $location);
        self::assertStringContainsString('state=' . self::STATE, $location);

        $code = $this->codeFrom($location);
        self::assertNotSame('', $code);

        // The player id never travels in the URL - only the code, which is what makes the
        // redirect unforgeable.
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR, $location);

        $store = $browser->getContainer()->get(WjpfPairingCodeStore::class);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $store->consume($code));
    }

    public function testCancellingRedirectsBackWithAnError(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', self::PATH . '?state=' . self::STATE);
        $browser->submit($crawler->filter('button[value="cancel"]')->form());

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringNotContainsString('code=', $location);
    }

    public function testConfirmWithoutCsrfTokenIsRejected(): void
    {
        $browser = $this->signedIn();
        $browser->request('POST', self::PATH, ['action' => 'connect', 'state' => self::STATE]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function codeFrom(string $location): string
    {
        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);

        return is_string($parameters['code'] ?? null) ? $parameters['code'] : '';
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }
}
