<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\DetectBoxTextMismatches;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Results\BoxTextDetectionOutcome;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/puzzle-approvals.md, "Box-text mismatches": the detection compares each analysed box with its record,
 * moderators see the list and dismiss - a dismissed mismatch never returns while the box reads the same.
 */
final class BoxTextMismatchesControllerTest extends WebTestCase
{
    private const string IMAGE_A = 'box-500-01-018d0003-boxtext.jpg';

    private const string IMAGE_B = 'box-500-02-018d0003-boxtext.jpg';

    public function testAModeratorReachesTheListAPlayerDoesNot(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/admin/box-text-mismatches');
        self::assertResponseStatusCodeSame(403);

        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/admin/box-text-mismatches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Box-text mismatches');
    }

    public function testDetectsListsAndRemembersADismissal(): void
    {
        $browser = self::createClient();
        // 500_01: the box says 1000 pieces and another EAN; 500_02: the box agrees with the record
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, [
            'pieces' => [['v' => 1000, 'evidence' => '1000 Teile']],
            'eans' => ['4005556173709'],
            'productCodes' => [],
            'brandMentions' => ['Ravensburger'],
        ]);
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_500_02, self::IMAGE_B, [
            'pieces' => [['v' => 500, 'evidence' => '500 pieces']],
            'brandMentions' => ['Ravensburger'],
        ]);

        $outcome = $this->detect($browser);
        self::assertGreaterThanOrEqual(2, $outcome->puzzlesChecked);
        self::assertSame(2, $outcome->mismatches);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="box-text-mismatch"]'));
        self::assertSelectorTextContains('body', '1000 Teile');

        // Filtered by field
        $crawler = $browser->request('GET', '/admin/box-text-mismatches?field=ean');
        self::assertCount(1, $crawler->filter('[data-testid="box-text-mismatch"]'));

        // Dismiss the pieces mismatch
        $crawler = $browser->request('GET', '/admin/box-text-mismatches?field=pieces');
        $form = $crawler->filter('[data-testid="box-text-mismatch"] form[action$="/dismiss"]')->form();
        $browser->submit($form);
        self::assertResponseRedirects('/admin/box-text-mismatches?field=pieces&sort=most_solved');
        $browser->followRedirect();
        self::assertSelectorTextContains('body', 'will not come back');
        self::assertCount(0, $browser->getCrawler()->filter('[data-testid="box-text-mismatch"]'));

        // The next run finds it again - and it stays dismissed; dismissing twice changes nothing
        $this->detect($browser);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertCount(1, $crawler->filter('[data-testid="box-text-mismatch"]'));
        $browser->submit($form);
        self::assertSame(1, $this->database($browser)->fetchOne('SELECT count(*) FROM box_text_mismatch_decision'));

        // A new value read on the box is a new mismatch
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, [
            'pieces' => [['v' => 1500, 'evidence' => '1500 pieces']],
            'eans' => ['4005556173709'],
        ]);
        $this->detect($browser);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches?field=pieces');
        self::assertCount(1, $crawler->filter('[data-testid="box-text-mismatch"]'));
        self::assertSelectorTextContains('body', '1500');
    }

    public function testARecordFixedSinceTheRunDropsOutAtOnce(): void
    {
        $browser = self::createClient();
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['eans' => ['4005556173709']]);
        $this->detect($browser);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertCount(1, $crawler->filter('[data-testid="box-text-mismatch"]'));

        $this->database($browser)->executeStatement("UPDATE puzzle SET ean = '4005556173709' WHERE id = ?", [PuzzleFixture::PUZZLE_500_01]);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertCount(0, $crawler->filter('[data-testid="box-text-mismatch"]'));

        // ... and the next run removes the row
        $outcome = $this->detect($browser);
        self::assertSame(0, $outcome->mismatches);
        self::assertSame(1, $outcome->removed);
    }

    public function testAnAnalysisOfAnOlderPictureOrAHiddenPuzzleIsNotCompared(): void
    {
        $browser = self::createClient();
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['pieces' => [['v' => 2000, 'evidence' => '2000']]]);
        $this->storeBoxText($browser, PuzzleFixture::PUZZLE_HIDDEN_IMAGE, 'hidden.jpg', ['pieces' => [['v' => 2000, 'evidence' => '2000']]]);
        $this->database($browser)->executeStatement("UPDATE puzzle SET image = 'box-500-01-new.jpg' WHERE id = ?", [PuzzleFixture::PUZZLE_500_01]);

        self::assertSame(0, $this->detect($browser)->mismatches);
    }

    /**
     * @param array<string, mixed> $boxText
     */
    private function storeBoxText(KernelBrowser $browser, string $puzzleId, string $image, array $boxText): void
    {
        $database = $this->database($browser);
        $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        $now = TestClock::SQL_NOW;
        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
VALUES (:id, :puzzleId, 'box_text', '1.0.0+rules', :fingerprint, CAST(:result AS jsonb), NULL, {$now})
ON CONFLICT (analyzer, puzzle_id) DO UPDATE SET image_fingerprint = EXCLUDED.image_fingerprint, result = EXCLUDED.result
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzleId' => $puzzleId,
                'fingerprint' => PuzzleImageFingerprint::of($image),
                'result' => json_encode($boxText, JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function detect(KernelBrowser $browser): BoxTextDetectionOutcome
    {
        $outcome = $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new DetectBoxTextMismatches())->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(BoxTextDetectionOutcome::class, $outcome);

        return $outcome;
    }

    private function database(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
