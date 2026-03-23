<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FeatureRequest;

use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeatureRequestDetailControllerTest extends WebTestCase
{
    public function testDetailPageLoadsForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_POPULAR);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Add dark mode support');
    }

    public function testDetailPageLoadsForAuthenticatedNonMember(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_POPULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testDetailPageLoadsForMember(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_POPULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testDetailPage404ForInvalidId(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests/invalid-id');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDetailPage404ForNonexistentId(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMemberCanSubmitComment(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_NEW);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Add Comment', [
            'feature_request_comment_form[content]' => 'This is a test comment from controller test.',
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }
}
