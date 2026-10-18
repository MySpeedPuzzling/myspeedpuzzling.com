<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "Puzzle image analysis": the pipeline's work list, storing one analysis or a batch,
 * and reading a puzzle's analyses back. Hidden images are never listed, read or accepted.
 */
final class PuzzleImageAnalysisInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string UNKNOWN_PUZZLE = '018d0003-0000-0000-0000-0000000009ff';

    private const array IMAGES = [
        PuzzleFixture::PUZZLE_500_01 => 'box-500-01-018d0003-aaaa0001.jpg',
        PuzzleFixture::PUZZLE_500_02 => 'box-500-02-018d0003-aaaa0002.jpg',
        PuzzleFixture::PUZZLE_500_03 => 'box-500-03-018d0003-aaaa0003.jpg',
        PuzzleFixture::PUZZLE_HIDDEN_IMAGE => '0123456789abcdef0123456789abcdef.jpg',
    ];

    public function testEveryEndpointNeedsTheToken(): void
    {
        $browser = self::createClient();

        foreach (
            [
            ['GET', '/internal-api/puzzle-images'],
            ['GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/image-analysis'],
            ['PUT', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/image-analysis/colors'],
            ['POST', '/internal-api/puzzle-image-analyses'],
            ['GET', '/internal-api/puzzle-image-analyses?analyzer=colors'],
            ['POST', '/internal-api/puzzle-image-claims'],
            ['POST', '/internal-api/puzzle-image-claims/release'],
            ] as [$method, $uri]
        ) {
            self::callInternalApi($browser, $method, $uri, ['items' => []], token: null);
            self::assertResponseStatusCodeSame(401, "$method $uri");
        }
    }

    public function testListsShownImagesPageByPage(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $listed = [];
        $after = null;
        $pages = 0;

        do {
            $page = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?limit=2' . ($after !== null ? '&after=' . $after : ''));
            self::assertResponseIsSuccessful();
            self::assertIsArray($page['puzzles']);
            self::assertLessThanOrEqual(2, count($page['puzzles']));

            foreach ($page['puzzles'] as $puzzle) {
                self::assertIsArray($puzzle);
                self::assertIsString($puzzle['puzzleId']);
                $listed[$puzzle['puzzleId']] = $puzzle;
            }

            $after = $page['nextCursor'];
            self::assertTrue($after === null || is_string($after));
            $pages++;
        } while ($after !== null && $pages < 50);

        self::assertNull($after);
        $ids = array_keys($listed);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'in puzzle id order, every puzzle once');

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, CompetitionApiFixture::PUZZLE_VISIBLE] as $shown) {
            self::assertArrayHasKey($shown, $listed);
        }

        // Hidden pictures (secret competition puzzles, placeholders) and puzzles without one are never listed
        foreach ([PuzzleFixture::PUZZLE_HIDDEN_IMAGE, CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, PuzzleFixture::PUZZLE_1000_01] as $notShown) {
            self::assertArrayNotHasKey($notShown, $listed);
        }

        $puzzle = $listed[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]), $puzzle['imageFingerprint']);
        self::assertIsString($puzzle['imageUrl']);
        self::assertStringEndsWith('/original/' . self::IMAGES[PuzzleFixture::PUZZLE_500_01], $puzzle['imageUrl']);
        self::assertMatchesRegularExpression('#^https?://#', $puzzle['imageUrl']);
        self::assertSame(500, $puzzle['piecesCount']);
        self::assertIsArray($puzzle['manufacturer']);
        self::assertSame([], $puzzle['analyses']);
    }

    public function testFiltersByWhatIsStoredForTheAnalyzer(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        // 500_01 analysed (current picture, v1), 500_02 analysed from its previous picture, 500_03 never
        self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'colors'), self::body(PuzzleFixture::PUZZLE_500_01, 'v1'));
        self::assertResponseIsSuccessful();
        self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_02, 'colors'), self::body(PuzzleFixture::PUZZLE_500_02, 'v1'));
        self::assertResponseIsSuccessful();
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => 'box-500-02-new.jpg', 'id' => PuzzleFixture::PUZZLE_500_02]);

        $missing = $this->listedIds($browser, 'analyzer=colors&status=missing');
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, $missing);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, $missing);
        self::assertContains(PuzzleFixture::PUZZLE_500_03, $missing);

        $stale = $this->listedIds($browser, 'analyzer=colors&status=stale');
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, $stale);
        self::assertContains(PuzzleFixture::PUZZLE_500_02, $stale);
        self::assertContains(PuzzleFixture::PUZZLE_500_03, $stale);

        // A newer analyzer version makes every older analysis stale
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->listedIds($browser, 'analyzer=colors&version=v2&status=stale'));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, $this->listedIds($browser, 'analyzer=colors&version=v1&status=stale'));

        // Another analyzer knows nothing of the colors
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->listedIds($browser, 'analyzer=tags&status=missing'));

        $all = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?after=' . PuzzleFixture::PUZZLE_500_01 . '&limit=1');
        self::assertIsArray($all['puzzles']);
        self::assertIsArray($all['puzzles'][0]);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $all['puzzles'][0]['puzzleId']);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $all['nextCursor']);
        self::assertIsArray($all['puzzles'][0]['analyses']);
        self::assertIsArray($all['puzzles'][0]['analyses']['colors']);
        self::assertSame('v1', $all['puzzles'][0]['analyses']['colors']['analyzerVersion']);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_02]), $all['puzzles'][0]['analyses']['colors']['imageFingerprint']);
        self::assertNotSame($all['puzzles'][0]['imageFingerprint'], $all['puzzles'][0]['analyses']['colors']['imageFingerprint']);
    }

    public function testRefusesInvalidListParameters(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?status=missing&limit=5000&after=nope&analyser=colors');
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertSame(['after', 'analyser', 'analyzer', 'limit'], self::sortedKeys($answer['errors']));
    }

    public function testStoresAnAnalysisAndReadsItBack(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $stored = self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'artwork'), [
            'analyzerVersion' => 'crop-v1',
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]),
            'analyzedAt' => '2026-10-18T06:00:00Z',
            'result' => ['bbox' => ['x' => 0.05, 'y' => 0.1, 'w' => 0.9, 'h' => 0.8], 'isPhoto' => true],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $stored['puzzleId']);
        self::assertSame('artwork', $stored['analyzer']);
        self::assertSame('crop-v1', $stored['analyzerVersion']);
        self::assertTrue($stored['current']);
        self::assertSame('2026-10-18T06:00:00+00:00', $stored['analyzedAt']);
        self::assertIsString($stored['receivedAt']);
        // jsonb keeps the values, not the order of the keys
        self::assertEquals(['bbox' => ['x' => 0.05, 'y' => 0.1, 'w' => 0.9, 'h' => 0.8], 'isPhoto' => true], $stored['result']);

        // A newer push replaces it
        self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'artwork'), [
            'analyzerVersion' => 'crop-v2',
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]),
            'result' => ['bbox' => null, 'isPhoto' => false],
        ]);
        self::assertResponseIsSuccessful();

        $read = self::callInternalApi($browser, 'GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/image-analysis');
        self::assertResponseIsSuccessful();
        self::assertIsArray($read['analyses']);
        self::assertSame(['artwork'], array_keys($read['analyses']));
        self::assertIsArray($read['analyses']['artwork']);
        self::assertSame('crop-v2', $read['analyses']['artwork']['analyzerVersion']);
        self::assertNull($read['analyses']['artwork']['analyzedAt']);
        self::assertSame(['bbox' => null, 'isPhoto' => false], $read['analyses']['artwork']['result']);

        $none = self::callInternalApi($browser, 'GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_02 . '/image-analysis');
        self::assertResponseIsSuccessful();
        self::assertSame([], $none['analyses']);
    }

    public function testRefusesWhatCannotBeStored(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        self::callInternalApi($browser, 'PUT', $this->putUri(self::UNKNOWN_PUZZLE, 'colors'), self::body(PuzzleFixture::PUZZLE_500_01, 'v1'));
        self::assertResponseStatusCodeSame(404);

        // A hidden picture reads as unknown - written, listed and read alike
        self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, 'colors'), self::body(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, 'v1'));
        self::assertResponseStatusCodeSame(404);
        self::callInternalApi($browser, 'GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE . '/image-analysis');
        self::assertResponseStatusCodeSame(404);

        $conflict = self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'colors'), ['imageFingerprint' => 'an-older-picture'] + self::body(PuzzleFixture::PUZZLE_500_01, 'v1'));
        self::assertResponseStatusCodeSame(409);
        self::assertIsString($conflict['error']);
        self::assertStringContainsString(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]), $conflict['error']);

        $invalid = self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'artwork'), [
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]),
            'result' => ['bbox' => ['x' => 0.5, 'y' => 0.5, 'w' => 0.9, 'h' => 0.1]],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertIsArray($invalid['errors']);
        self::assertSame(['analyzerVersion', 'result.bbox'], self::sortedKeys($invalid['errors']));

        $unknownAnalyzer = self::callInternalApi($browser, 'PUT', $this->putUri(PuzzleFixture::PUZZLE_500_01, 'palette'), self::body(PuzzleFixture::PUZZLE_500_01, 'v1'));
        self::assertResponseStatusCodeSame(422);
        self::assertIsArray($unknownAnalyzer['errors']);
        self::assertArrayHasKey('analyzer', $unknownAnalyzer['errors']);

        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis'));
    }

    public function testABatchStoresWhatItCan(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-analyses', ['items' => [
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'analyzer' => 'colors'] + self::body(PuzzleFixture::PUZZLE_500_01, 'v1'),
            ['puzzleId' => PuzzleFixture::PUZZLE_500_02, 'analyzer' => 'colors', 'imageFingerprint' => 'old'] + self::body(PuzzleFixture::PUZZLE_500_02, 'v1'),
            ['puzzleId' => self::UNKNOWN_PUZZLE, 'analyzer' => 'colors'] + self::body(PuzzleFixture::PUZZLE_500_01, 'v1'),
            ['puzzleId' => PuzzleFixture::PUZZLE_500_03, 'analyzer' => 'colours'] + self::body(PuzzleFixture::PUZZLE_500_03, 'v1'),
            ['puzzleId' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE, 'analyzer' => 'tags'] + self::body(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, 'v1'),
            ['puzzleId' => PuzzleFixture::PUZZLE_500_03, 'analyzer' => 'tags'] + self::body(PuzzleFixture::PUZZLE_500_03, 'v1'),
        ]]);
        self::assertResponseIsSuccessful();

        self::assertSame(['stored' => 2, 'stale_fingerprint' => 1, 'not_found' => 2, 'invalid' => 1], $answer['counts']);
        self::assertIsArray($answer['items']);
        self::assertSame(
            ['stored', 'stale_fingerprint', 'not_found', 'invalid', 'not_found', 'stored'],
            array_map(static fn (mixed $item): mixed => is_array($item) ? $item['status'] : null, $answer['items']),
        );
        self::assertIsArray($answer['items'][1]);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_02]), $answer['items'][1]['currentFingerprint']);
        self::assertIsArray($answer['items'][3]);
        self::assertSame(3, $answer['items'][3]['index']);
        self::assertIsArray($answer['items'][3]['errors']);
        self::assertArrayHasKey('analyzer', $answer['items'][3]['errors']);

        self::assertSame(2, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis'));
    }

    public function testABatchMustBeAListOfAtMost200(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-analyses', ['items' => array_fill(0, 201, ['puzzleId' => PuzzleFixture::PUZZLE_500_01])]);
        self::assertResponseStatusCodeSame(422);

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-analyses', ['items' => []]);
        self::assertResponseStatusCodeSame(422);

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-analyses', ['analyses' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWorkersClaimImagesStoreAndRelease(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $studio = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-claims', [
            'analyzer' => 'describe',
            'version' => '1.0.0+qwen3vl8b-4bit',
            'worker' => 'mac-studio',
            'limit' => 2,
            'leaseSeconds' => 600,
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($studio['puzzles']);
        self::assertCount(2, $studio['puzzles']);
        $studioIds = array_map(static fn (mixed $puzzle): string => is_array($puzzle) && is_string($puzzle['puzzleId']) ? $puzzle['puzzleId'] : '', $studio['puzzles']);

        // The work list's shape, with the claim on it
        $first = $studio['puzzles'][0];
        self::assertIsArray($first);
        self::assertIsString($first['imageUrl']);
        self::assertIsString($first['imageFingerprint']);
        self::assertIsArray($first['claims']);
        self::assertIsArray($first['claims']['describe']);
        self::assertSame('mac-studio', $first['claims']['describe']['worker']);

        $mini = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-claims', [
            'analyzer' => 'describe',
            'version' => '1.0.0+qwen3vl8b-4bit',
            'worker' => 'mac-mini',
            'limit' => 200,
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($mini['puzzles']);
        $miniIds = array_map(static fn (mixed $puzzle): string => is_array($puzzle) && is_string($puzzle['puzzleId']) ? $puzzle['puzzleId'] : '', $mini['puzzles']);
        self::assertSame([], array_intersect($studioIds, $miniIds));
        self::assertContains(PuzzleFixture::PUZZLE_500_03, array_merge($studioIds, $miniIds));
        self::assertNotContains(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, array_merge($studioIds, $miniIds));

        // The read-only list shows who holds what
        $listed = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?after=' . PuzzleFixture::PUZZLE_500_02 . '&limit=1');
        self::assertIsArray($listed['puzzles']);
        self::assertIsArray($listed['puzzles'][0]);
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $listed['puzzles'][0]['puzzleId']);
        self::assertIsArray($listed['puzzles'][0]['claims']);
        self::assertArrayHasKey('describe', $listed['puzzles'][0]['claims']);

        // Storing a result lets go of its claim
        $stored = $first['puzzleId'];
        self::assertIsString($stored);
        self::callInternalApi($browser, 'PUT', $this->putUri($stored, 'describe'), [
            'analyzerVersion' => '1.0.0+qwen3vl8b-4bit',
            'imageFingerprint' => $first['imageFingerprint'],
            'result' => ['caption' => 'A lake below snowy mountains.'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis_claim WHERE puzzle_id = :id', ['id' => $stored]));

        $released = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-claims/release', ['worker' => 'mac-studio', 'analyzer' => 'describe']);
        self::assertResponseIsSuccessful();
        self::assertSame(['released' => 1], $released);
        self::assertSame(count($miniIds), $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_image_analysis_claim'));
    }

    public function testRefusesInvalidClaims(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-claims', [
            'analyzer' => 'palette',
            'worker' => str_repeat('w', 65),
            'limit' => 500,
            'leaseSeconds' => 10,
            'lease' => 60,
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertSame(['analyzer', 'lease', 'leaseSeconds', 'limit', 'version', 'worker'], self::sortedKeys($answer['errors']));

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-image-claims/release', ['analyzer' => 'tags']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testReadsStoredResultsInBulk(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03] as $puzzleId) {
            self::callInternalApi($browser, 'PUT', $this->putUri($puzzleId, 'embed_dinov2'), [
                'analyzerVersion' => '1.0.0+dinov2-l',
                'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[$puzzleId]),
                'result' => ['dim' => 1024, 'dtype' => 'f16', 'cls' => base64_encode(str_repeat("\0", 2048))],
            ]);
            self::assertResponseIsSuccessful();
        }

        // 500_02 gets a new picture - its embedding is of the old one
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => 'box-500-02-new.jpg', 'id' => PuzzleFixture::PUZZLE_500_02]);

        $first = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-image-analyses?analyzer=embed_dinov2&limit=2');
        self::assertResponseIsSuccessful();
        self::assertSame('embed_dinov2', $first['analyzer']);
        self::assertIsArray($first['items']);
        self::assertCount(2, $first['items']);
        self::assertIsArray($first['items'][0]);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $first['items'][0]['puzzleId']);
        self::assertTrue($first['items'][0]['current']);
        self::assertIsArray($first['items'][0]['result']);
        self::assertSame(1024, $first['items'][0]['result']['dim']);
        self::assertIsArray($first['items'][1]);
        self::assertFalse($first['items'][1]['current']);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $first['nextCursor']);

        $second = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-image-analyses?analyzer=embed_dinov2&limit=2&after=' . PuzzleFixture::PUZZLE_500_02);
        self::assertIsArray($second['items']);
        self::assertCount(1, $second['items']);
        self::assertNull($second['nextCursor']);

        $current = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-image-analyses?analyzer=embed_dinov2&onlyCurrent=1');
        self::assertIsArray($current['items']);
        self::assertSame(
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_03],
            array_map(static fn (mixed $item): mixed => is_array($item) ? $item['puzzleId'] : null, $current['items']),
        );

        $invalid = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-image-analyses?limit=0&onlyCurrent=yes');
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertSame(['analyzer', 'limit', 'onlyCurrent'], self::sortedKeys($invalid['errors']));
    }

    private function giveImages(): void
    {
        foreach (self::IMAGES as $puzzleId => $image) {
            $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(string $puzzleId, string $version): array
    {
        return [
            'analyzerVersion' => $version,
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[$puzzleId]),
            'result' => ['palette' => ['#112233'], 'tags' => ['cats']],
        ];
    }

    private function putUri(string $puzzleId, string $analyzer): string
    {
        return sprintf('/internal-api/puzzles/%s/image-analysis/%s', $puzzleId, $analyzer);
    }

    /**
     * @return list<string>
     */
    private function listedIds(KernelBrowser $browser, string $query): array
    {
        $page = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?limit=1000&' . $query);
        self::assertResponseIsSuccessful();
        self::assertIsArray($page['puzzles']);

        return array_values(array_map(static fn (mixed $puzzle): string => is_array($puzzle) && is_string($puzzle['puzzleId']) ? $puzzle['puzzleId'] : '', $page['puzzles']));
    }

    /**
     * @param array<mixed> $errors
     *
     * @return list<string>
     */
    private static function sortedKeys(array $errors): array
    {
        $keys = array_map(strval(...), array_keys($errors));
        sort($keys);

        return $keys;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
