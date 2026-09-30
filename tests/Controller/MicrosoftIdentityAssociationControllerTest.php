<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestDouble\MicrosoftIdTokenFactory;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Microsoft's publisher-domain verification file, served from
 * MICROSOFT_CLIENT_ID (setup-microsoft.md, "Branding & properties").
 */
final class MicrosoftIdentityAssociationControllerTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testServesTheApplicationIdAsJson(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Microsoft);
        $browser = self::createClient();

        $browser->request('GET', '/.well-known/microsoft-identity-association.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            ['associatedApplications' => [['applicationId' => MicrosoftIdTokenFactory::CLIENT_ID]]],
            json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        // No session for a verifier bot
        self::assertSame([], $browser->getResponse()->headers->getCookies());
    }

    public function testNotFoundWithoutAClientId(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Microsoft);
        $browser = self::createClient();

        $browser->request('GET', '/.well-known/microsoft-identity-association.json');

        self::assertResponseStatusCodeSame(404);
    }
}
