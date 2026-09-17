<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddPuzzleToRoundControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseRedirects();
    }

    public function testAdminCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessPageForSeriesEditionRound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionSeriesFixture::ROUND_EJJ_69);

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleAlreadyInAnotherRoundOfTheSameCategoryIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $form = $crawler->filter('form')->last()->form();
        $prefix = (string) $crawler->filter('input[name$="[puzzle]"]')->attr('name');
        $prefix = substr($prefix, 0, (int) strpos($prefix, '['));

        // PUZZLE_500_01 is already in the (solo) qualification round of the same competition
        $browser->submit($form, [
            $prefix . '[brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            $prefix . '[puzzle]' => PuzzleFixture::PUZZLE_500_01,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'already in round "Qualification Round"');
    }
}
