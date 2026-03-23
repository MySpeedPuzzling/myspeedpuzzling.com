<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FeatureRequest;

use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VoteForFeatureRequestControllerTest extends WebTestCase
{
    public function testAnonymousUserCannotVote(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_NEW . '/vote');

        $this->assertResponseRedirects();
    }

    public function testNonMemberCannotVote(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/feature-requests/' . FeatureRequestFixture::FEATURE_REQUEST_NEW . '/vote');

        $this->assertResponseRedirects();
    }
}
