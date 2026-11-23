<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeedsCropTrustAnalyses;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PuzzlesNeedingImprovementControllerTest extends WebTestCase
{
    use SeedsCropTrustAnalyses;

    public function testGuestSeesTheListNotIndexed(): void
    {
        $browser = $this->client();

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000));
        self::assertCount(1, $crawler->filter('#improvement-ean-' . PuzzleFixture::PUZZLE_9000));
        // A better image wanted, for everybody
        self::assertCount(1, $crawler->filter('#improvement-image-' . PuzzleFixture::PUZZLE_300));
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_300));
        // Guests go to the form page (sign-in first), not into the modal
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000 . '[data-turbo-frame="modal-frame"]'));
        // No collection filter, no wrong pictures
        self::assertCount(0, $crawler->filter('input[name="collection"]'));
        self::assertCount(0, $crawler->filter('#improvement-missing option[value="wrong-picture"]'));
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_1500_01));
        self::assertCount(0, $crawler->filter('[id^="improvement-wrong-picture-"]'));
    }

    public function testGuestCannotAskForWrongPicturesNorACollection(): void
    {
        $browser = $this->client();

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?missing=wrong-picture&collection=1');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_1500_01));
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000), 'falls back to everything');
    }

    public function testPlayerOpensTheProposalInTheModal(): void
    {
        $browser = $this->client();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&page=99');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000 . '[data-turbo-frame="modal-frame"]'));
    }

    public function testPlayerFiltersTheirCollectionsImages(): void
    {
        $browser = $this->client();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?missing=image&collection=1');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="collection"][checked]'));
        self::assertCount(1, $crawler->filter('#improvement-missing option[value="image"][selected]'));
        self::assertCount(1, $crawler->filter('#improvement-image-' . PuzzleFixture::PUZZLE_2000 . '.bg-info'));
        // A new photo of PUZZLE_500_02 waits for review (CHANGE_REQUEST_WITH_IMAGE); the button stays
        self::assertCount(1, $crawler->filter('#improvement-image-' . PuzzleFixture::PUZZLE_500_02 . '.bg-success'));
        self::assertCount(1, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_500_02));
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_300), 'not in the collections');
        self::assertCount(0, $crawler->filter('#improvement-action-' . PuzzleFixture::PUZZLE_9000), 'only images');
        self::assertStringContainsString('2 puzzles needing a better image', $crawler->filter('main')->text());
    }

    public function testModeratorSeesWrongPictures(): void
    {
        $browser = $this->client();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?missing=wrong-picture');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#improvement-missing option[value="wrong-picture"][selected]'));
        self::assertCount(1, $crawler->filter('#improvement-wrong-picture-' . PuzzleFixture::PUZZLE_1500_01));
        self::assertCount(1, $crawler->filter('.list-group-item'));
    }

    public function testTheFiltersStaySelected(): void
    {
        $browser = $this->client();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzles-needing-improvement?missing=ean&brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&collection=1');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#improvement-missing option[value="ean"][selected]'));
        self::assertCount(1, $crawler->filter('#improvement-brand option[value="' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '"][selected]'));
    }

    public function testTheProposalAnswerMarksTheBadgesItCovers(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $puzzleId = PuzzleFixture::PUZZLE_300;

        $withImage = $twig->render('puzzle-report/_stream.html.twig', ['puzzle_id' => $puzzleId, 'message' => 'ok', 'mark_improvement_row' => true, 'proposed_ean' => false, 'proposed_image' => true]);
        self::assertStringContainsString('target="improvement-image-' . $puzzleId . '"', $withImage);
        self::assertStringContainsString('target="improvement-wrong-picture-' . $puzzleId . '"', $withImage);
        self::assertStringNotContainsString('target="improvement-ean-', $withImage);

        $namesOnly = $twig->render('puzzle-report/_stream.html.twig', ['puzzle_id' => $puzzleId, 'message' => 'ok', 'mark_improvement_row' => true]);
        self::assertStringContainsString('target="improvement-proposed-' . $puzzleId . '"', $namesOnly);
    }

    public function testFooterLinksToThePage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        self::assertCount(1, $crawler->filter('.footer-links a[href="/en/puzzles-needing-improvement"]'));
    }

    public function testPuzzlePageHintsSignedInPlayers(): void
    {
        $browser = $this->client();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_9000);
        self::assertCount(0, $crawler->filter('#puzzle-improvement-hint'), 'guests and crawlers get none');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_9000);
        self::assertStringContainsString('EAN missing', $crawler->filter('#puzzle-improvement-hint')->text());

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_300);
        self::assertStringContainsString('better image wanted', $crawler->filter('#puzzle-improvement-hint')->text());
        self::assertStringNotContainsString('EAN missing', $crawler->filter('#puzzle-improvement-hint')->text());

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1500_01);
        self::assertCount(0, $crawler->filter('#puzzle-improvement-hint'), 'the wrong picture is for moderators');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1500_01);
        self::assertStringContainsString('wrong picture', $crawler->filter('#puzzle-improvement-hint')->text());
    }

    private function client(): KernelBrowser
    {
        $browser = self::createClient();
        self::seedImageMarks(self::getContainer()->get(Connection::class));

        return $browser;
    }
}
