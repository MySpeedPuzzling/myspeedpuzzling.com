<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzlesNeedingImprovementControllerTest extends WebTestCase
{
    public function testGuestSeesTheListNotIndexed(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000));
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_300));
        // Guests go to the form page (sign-in first), not into the modal
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000 . ' a[data-turbo-frame="modal-frame"]'));
    }

    public function testPlayerOpensTheProposalInTheModal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&page=99');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000 . ' a[data-turbo-frame="modal-frame"]'));
    }

    public function testFooterLinksToThePage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        self::assertCount(1, $crawler->filter('.footer-links a[href="/en/puzzles-needing-improvement"]'));
    }
}
