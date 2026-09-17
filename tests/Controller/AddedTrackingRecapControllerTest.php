<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddedTrackingRecapControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
    }

    public function testCollectionCtaIsShownForPuzzleNotInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#recap-collection-cta');
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsAlreadyInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }
}
