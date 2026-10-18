<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ClaimPuzzleImages;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalysis;
use SpeedPuzzling\Web\Message\ReleasePuzzleImageClaims;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Claims of the image analysis pipeline (docs/features/internal-api.md, "Puzzle image analysis" - claims): two workers
 * never hold the same image for the same analyzer, an expired claim is anybody's, a worker's own claims are extended,
 * storing a result releases its claim, a worker releases only its own.
 */
final class ClaimPuzzleImagesHandlerTest extends KernelTestCase
{
    private const array IMAGES = [
        PuzzleFixture::PUZZLE_500_01 => 'claim-500-01.jpg',
        PuzzleFixture::PUZZLE_500_02 => 'claim-500-02.jpg',
        PuzzleFixture::PUZZLE_500_03 => 'claim-500-03.jpg',
        PuzzleFixture::PUZZLE_HIDDEN_IMAGE => 'claim-hidden.jpg',
    ];

    protected function setUp(): void
    {
        self::bootKernel();

        foreach (self::IMAGES as $puzzleId => $image) {
            $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        }
    }

    public function testTwoWorkersNeverHoldTheSameImage(): void
    {
        $first = $this->claim('mac-studio', limit: 2);
        $second = $this->claim('mac-mini', limit: 200);
        $third = $this->claim('third', limit: 200);

        self::assertCount(2, $first);
        self::assertNotSame([], $second);
        self::assertSame([], array_intersect($first, $second));
        // Everything still to do is held - nothing is left for a third worker
        self::assertSame([], $third);

        $all = array_merge($first, $second);
        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03] as $puzzleId) {
            self::assertContains($puzzleId, $all);
        }

        // Hidden images and puzzles without one are never claimed
        self::assertNotContains(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, $all);
        self::assertNotContains(PuzzleFixture::PUZZLE_1000_01, $all);

        self::assertSame(1, $this->database()->fetchOne(
            'SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM puzzle_image_analysis_claim GROUP BY analyzer, puzzle_id) counts',
        ));
    }

    /**
     * A worker that skipped or failed images it still holds keeps advancing: fresh work first, its own claims last
     */
    public function testFreshWorkComesBeforeTheWorkersOwnClaims(): void
    {
        $first = $this->claim('mac-studio', limit: 2);
        $second = $this->claim('mac-studio', limit: 2);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame([], array_intersect($first, $second), 'a second claim pre-fetches new images, not the held ones');

        // Once fresh work runs out, the worker's own claims fill the rest
        $everything = $this->claim('mac-studio', limit: 200);
        foreach (array_merge($first, $second) as $held) {
            self::assertContains($held, $everything);
        }
    }

    public function testAWorkerTakesItsOwnClaimsAgainAndExtendsThem(): void
    {
        // Everything still to do is held by this worker - claiming again gives its own claims, extended
        $first = $this->claim('mac-studio', limit: 200);
        $this->database()->executeStatement("UPDATE puzzle_image_analysis_claim SET expires_at = expires_at - INTERVAL '10 minutes'");
        $before = $this->database()->fetchOne('SELECT MIN(expires_at) FROM puzzle_image_analysis_claim');

        self::assertSame($first, $this->claim('mac-studio', limit: 200));
        self::assertGreaterThan($before, $this->database()->fetchOne('SELECT MIN(expires_at) FROM puzzle_image_analysis_claim'));
        self::assertSame(count($first), $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis_claim'));
    }

    public function testAnExpiredClaimIsAnybodys(): void
    {
        $taken = $this->claim('mac-studio', limit: 200);
        $this->database()->executeStatement("UPDATE puzzle_image_analysis_claim SET expires_at = '2000-01-01 00:00:00'");

        self::assertSame($taken, $this->claim('mac-mini', limit: 200));
        self::assertSame(['mac-mini'], $this->database()->fetchFirstColumn('SELECT DISTINCT worker FROM puzzle_image_analysis_claim'));
    }

    public function testClaimsArePerAnalyzer(): void
    {
        $colors = $this->claim('mac-studio', limit: 200);

        self::assertSame($colors, $this->claim('mac-mini', limit: 200, analyzer: ImageAnalyzer::Ocr));
    }

    public function testStoringAResultReleasesItsClaimAndTheImageIsDone(): void
    {
        $taken = $this->claim('mac-studio', limit: 200);
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $taken);

        $this->bus()->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(
            PuzzleFixture::PUZZLE_500_01,
            ImageAnalyzer::Colors,
            'v1',
            PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]),
            null,
            ['palette' => ['#000000']],
        )));

        self::assertSame(0, $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_image_analysis_claim WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_01],
        ));

        // Done for v1 - another worker does not get it; a new version makes it work again
        $this->database()->executeStatement("UPDATE puzzle_image_analysis_claim SET expires_at = '2000-01-01 00:00:00'");
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, $this->claim('mac-mini', limit: 200));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->claim('mac-mini', limit: 200, version: 'v2'));
    }

    public function testAWorkerReleasesOnlyItsOwnClaims(): void
    {
        $studio = $this->claim('mac-studio', limit: 2);
        $mini = $this->claim('mac-mini', limit: 200, analyzer: ImageAnalyzer::Tags);

        $released = $this->bus()->dispatch(new ReleasePuzzleImageClaims('mac-studio', puzzleIds: [$studio[0]]))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $released);
        self::assertSame(1, $released->getResult());

        $released = $this->bus()->dispatch(new ReleasePuzzleImageClaims('mac-studio'))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $released);
        self::assertSame(1, $released->getResult());

        self::assertSame(count($mini), $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis_claim'));
        self::assertSame($studio, $this->claim('another', limit: 2));
    }

    /**
     * `requires`: only puzzles whose current picture already has a result of every required analyzer, of any version
     */
    public function testRequiresHandsOutOnlyWhatTheCurrentPictureHasResultsFor(): void
    {
        // 500_01: artwork (an old version) + ocr; 500_02: artwork of its previous picture; 500_03: artwork only
        $this->store(PuzzleFixture::PUZZLE_500_01, ImageAnalyzer::Artwork, 'artwork-0.9');
        $this->store(PuzzleFixture::PUZZLE_500_01, ImageAnalyzer::Ocr);
        $this->store(PuzzleFixture::PUZZLE_500_02, ImageAnalyzer::Artwork);
        $this->store(PuzzleFixture::PUZZLE_500_03, ImageAnalyzer::Artwork);
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => 'claim-500-02-new.jpg', 'id' => PuzzleFixture::PUZZLE_500_02]);
        // The new picture, not the one the stores above left in the identity map
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_03],
            $this->claim('mac-air', limit: 200, requires: [ImageAnalyzer::Artwork]),
            'any version counts, a result of an older picture does not',
        );
        self::assertSame(
            [PuzzleFixture::PUZZLE_500_01],
            $this->claim('mac-studio', limit: 200, analyzer: ImageAnalyzer::Describe, requires: [ImageAnalyzer::Artwork, ImageAnalyzer::Ocr]),
        );

        // The rest is untouched: claims still exclude other workers' images, and an analysis of the new picture counts
        self::assertSame([], $this->claim('mac-mini', limit: 200, requires: [ImageAnalyzer::Artwork]));
        $this->store(PuzzleFixture::PUZZLE_500_02, ImageAnalyzer::Artwork, image: 'claim-500-02-new.jpg');
        self::assertSame([PuzzleFixture::PUZZLE_500_02], $this->claim('mac-mini', limit: 200, requires: [ImageAnalyzer::Artwork]));

        // Without requires everything still to do is handed out (backward compatible)
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->claim('mac-mini', limit: 200, analyzer: ImageAnalyzer::Barcode));
    }

    private function store(string $puzzleId, ImageAnalyzer $analyzer, string $version = 'v1', null|string $image = null): void
    {
        $this->bus()->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(
            $puzzleId,
            $analyzer,
            $version,
            PuzzleImageFingerprint::of($image ?? self::IMAGES[$puzzleId]),
            null,
            $analyzer === ImageAnalyzer::Artwork ? ['bbox' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.8]] : ['lines' => []],
        )));
    }

    /**
     * @param list<ImageAnalyzer> $requires
     *
     * @return list<string>
     */
    private function claim(string $worker, int $limit, ImageAnalyzer $analyzer = ImageAnalyzer::Colors, string $version = 'v1', array $requires = []): array
    {
        $handled = $this->bus()->dispatch(new ClaimPuzzleImages($analyzer, $version, $worker, $limit, requires: $requires))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $handled);

        /** @var list<string> $puzzleIds */
        $puzzleIds = $handled->getResult();

        return $puzzleIds;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
