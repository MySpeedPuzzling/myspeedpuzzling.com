<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Facebook's Login Dialog never asks again for a permission the user once
 * declined - unless the URL says it is a re-request. Without `email` we
 * cannot sign anybody up, so BOTH start routes (login + connect) must send
 * `auth_type=rerequest`, and only for Facebook.
 */
final class FacebookAuthorizationUrlTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testLoginStartReRequestsDeclinedPermissions(): void
    {
        $browser = $this->clientWithFacebookEnabled();

        $browser->request('GET', '/login/social/facebook');

        $query = $this->facebookDialogQuery($browser);
        self::assertSame('rerequest', $query['auth_type'] ?? null);
        $scope = $query['scope'] ?? null;
        self::assertIsString($scope);
        self::assertStringContainsString('email', $scope);
    }

    public function testConnectStartReRequestsDeclinedPermissions(): void
    {
        $browser = $this->clientWithFacebookEnabled();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/account/social/facebook/connect');

        $query = $this->facebookDialogQuery($browser);
        self::assertSame('rerequest', $query['auth_type'] ?? null);
    }

    public function testOtherProvidersGetNoFacebookOnlyParameters(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('auth_type', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function clientWithFacebookEnabled(): KernelBrowser
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);

        return self::createClient();
    }

    /**
     * @return array<mixed>
     */
    private function facebookDialogQuery(KernelBrowser $browser): array
    {
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringStartsWith(
            'https://www.facebook.com/' . SocialLoginProviders::FACEBOOK_GRAPH_API_VERSION . '/dialog/oauth',
            $location,
        );

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }
}
