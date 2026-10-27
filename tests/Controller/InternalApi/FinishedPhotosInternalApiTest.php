<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\PruneFinishedPhotoAnalyses;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FinishedPhotoHttpMock;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/internal-api.md, "Finished-puzzle photos": the image pipeline's work list of players' finished-puzzle
 * photos, handing a photo over, storing analyses and reading them back. A photo is known by its fingerprint only - no
 * player, no solving time, no stored path ever in an answer; a private player's photo and a hidden puzzle's never.
 */
final class FinishedPhotosInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string PUZZLE = PuzzleFixture::PUZZLE_500_01;

    private const string IMAGE = 'box-500-01-018d0003-aaaa0001.jpg';

    private const string VERSION = '1.0.0+sift-siglip2-dinov2';

    /** @var list<array{id: string, player: string, path: string}> */
    private array $photos = [];

    protected function setUp(): void
    {
        parent::setUp();
        FinishedPhotoHttpMock::reset();
    }

    public function testEveryEndpointNeedsTheToken(): void
    {
        $browser = self::createClient();

        foreach (
            [
                ['GET', '/internal-api/finished-photos'],
                ['GET', '/internal-api/puzzles/' . self::PUZZLE . '/finished-photos/0123456789abcdef'],
                ['POST', '/internal-api/finished-photo-analyses'],
                ['GET', '/internal-api/finished-photo-analyses'],
            ] as [$method, $uri]
        ) {
            self::callInternalApi($browser, $method, $uri, ['items' => []], token: null);
            self::assertResponseStatusCodeSame(401, "$method $uri");
        }
    }

    public function testListsThePhotosByFingerprintOnlyNeverWhoseTheyAre(): void
    {
        $browser = self::createClient();
        $this->givePhotos();

        $page = self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?limit=1000');
        self::assertResponseIsSuccessful();
        self::assertNull($page['nextCursor']);
        $puzzle = self::puzzleOf($page, self::PUZZLE);

        self::assertSame(500, $puzzle['piecesCount']);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGE), $puzzle['imageFingerprint']);
        self::assertIsString($puzzle['imageUrl']);
        self::assertStringEndsWith('/original/' . self::IMAGE, $puzzle['imageUrl']);
        self::assertIsArray($puzzle['photos']);

        // The private player's photo is left out; the others in the order their results were saved
        self::assertSame(
            [PuzzleImageFingerprint::of($this->photos[0]['path']), PuzzleImageFingerprint::of($this->photos[1]['path'])],
            array_column($puzzle['photos'], 'photoFingerprint'),
        );

        foreach ($puzzle['photos'] as $photo) {
            self::assertIsArray($photo);
            self::assertIsString($photo['photoUrl']);
            self::assertIsString($photo['photoFingerprint']);
            self::assertStringEndsWith('/internal-api/puzzles/' . self::PUZZLE . '/finished-photos/' . $photo['photoFingerprint'], $photo['photoUrl']);
            self::assertNull($photo['analysis']);
        }

        // Nothing in the answer names a player, a solving time or a stored path
        $json = (string) $browser->getResponse()->getContent();

        foreach ($this->photos as $photo) {
            self::assertStringNotContainsString($photo['id'], $json);
            self::assertStringNotContainsString($photo['player'], $json);
        }

        self::assertStringNotContainsString('players/', $json);

        // perPuzzle takes the oldest results' photos
        $one = self::puzzleOf(self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?limit=1000&perPuzzle=1'), self::PUZZLE);
        self::assertIsArray($one['photos']);
        self::assertSame([PuzzleImageFingerprint::of($this->photos[0]['path'])], array_column($one['photos'], 'photoFingerprint'));

        // A puzzle hidden now is never listed
        $this->database()->executeStatement("UPDATE puzzle SET hide_image_until = '2099-01-01' WHERE id = ?", [self::PUZZLE]);
        $hidden = self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?limit=1000');
        self::assertIsArray($hidden['puzzles']);
        self::assertNotContains(self::PUZZLE, array_column($hidden['puzzles'], 'puzzleId'));
    }

    public function testPagesFollowTheCursor(): void
    {
        $browser = self::createClient();
        $this->givePhotos();
        $listed = [];
        $after = null;
        $pages = 0;

        do {
            $page = self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?limit=1' . ($after !== null ? '&after=' . $after : ''));
            self::assertResponseIsSuccessful();
            self::assertIsArray($page['puzzles']);
            $listed = [...$listed, ...array_values(array_filter(array_column($page['puzzles'], 'puzzleId'), is_string(...)))];
            $after = $page['nextCursor'];
            self::assertTrue($after === null || is_string($after));
            $pages++;
        } while ($after !== null && $pages < 100);

        self::assertNull($after);
        self::assertContains(self::PUZZLE, $listed);
        self::assertSame($listed, array_values(array_unique($listed)));
    }

    public function testRefusesUnknownParameters(): void
    {
        $browser = self::createClient();

        foreach (['?status=done', '?analyzer=artwork', '?limit=0', '?perPuzzle=21', '?after=nope', '?puzzle=1'] as $query) {
            self::callInternalApi($browser, 'GET', '/internal-api/finished-photos' . $query);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }

    public function testHandsOverTheProxysRenditionOfACurrentPhotoOnly(): void
    {
        $browser = self::createClient();
        $this->givePhotos();
        $fingerprint = PuzzleImageFingerprint::of($this->photos[0]['path']);

        $browser->request('GET', self::photoUri(self::PUZZLE, $fingerprint), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::INTERNAL_API_TOKEN]);
        self::assertResponseIsSuccessful();
        self::assertSame(FinishedPhotoHttpMock::JPEG, $browser->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        // The proxy's 1200 px rendition (metadata stripped), never the original
        self::assertCount(1, FinishedPhotoHttpMock::urls());
        self::assertStringEndsWith('/preset:puzzle_large/plain/' . $this->photos[0]['path'], FinishedPhotoHttpMock::urls()[0]);

        // The private player's photo, another puzzle's id, an unknown fingerprint: 404
        foreach (
            [
            [self::PUZZLE, PuzzleImageFingerprint::of($this->photos[2]['path'])],
            [PuzzleFixture::PUZZLE_500_02, $fingerprint],
            [self::PUZZLE, '0123456789abcdef'],
            ] as [$puzzleId, $photo]
        ) {
            self::callInternalApi($browser, 'GET', self::photoUri($puzzleId, $photo));
            self::assertResponseStatusCodeSame(404, "$puzzleId $photo");
        }

        // The proxy has it not: 404; the proxy fails: 503 for a retry
        $this->setPhoto($this->photos[0]['id'], 'players/x/' . FinishedPhotoHttpMock::MISSING . '.jpg');
        self::callInternalApi($browser, 'GET', self::photoUri(self::PUZZLE, PuzzleImageFingerprint::of('players/x/' . FinishedPhotoHttpMock::MISSING . '.jpg')));
        self::assertResponseStatusCodeSame(404);
        $this->setPhoto($this->photos[0]['id'], 'players/x/' . FinishedPhotoHttpMock::BROKEN . '.jpg');
        $browser->request('GET', self::photoUri(self::PUZZLE, PuzzleImageFingerprint::of('players/x/' . FinishedPhotoHttpMock::BROKEN . '.jpg')), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::INTERNAL_API_TOKEN]);
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHasHeader('Retry-After');
    }

    public function testStoresAnalysesAndListsWhatIsStillToDo(): void
    {
        $browser = self::createClient();
        $this->givePhotos();
        $first = PuzzleImageFingerprint::of($this->photos[0]['path']);
        $second = PuzzleImageFingerprint::of($this->photos[1]['path']);
        $private = PuzzleImageFingerprint::of($this->photos[2]['path']);

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/finished-photo-analyses', ['items' => [
            self::item($first),
            self::item($private),
            self::item($second, imageFingerprint: 'feedfacefeedface'),
            self::item($first, analyzer: 'artwork'),
            self::item($first, puzzleId: '018d0003-0000-0000-0000-0000000009ff'),
            self::item($first, result: ['quality' => 'better']),
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['stored' => 2, 'stale_fingerprint' => 2, 'not_found' => 1, 'invalid' => 1], $answer['counts']);
        self::assertIsArray($answer['items']);
        self::assertSame(['stored', 'stale_fingerprint', 'stale_fingerprint', 'invalid', 'not_found', 'stored'], array_column($answer['items'], 'status'));
        self::assertIsArray($answer['items'][2]);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGE), $answer['items'][2]['currentFingerprint']);

        // One row per photo - the later item of the batch replaced the earlier one
        self::assertSame(1, $this->database()->fetchOne('SELECT COUNT(*) FROM finished_photo_analysis'));

        $todo = self::puzzleOf(self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?status=stale&version=' . rawurlencode(self::VERSION) . '&limit=1000'), self::PUZZLE);
        self::assertIsArray($todo['photos']);
        self::assertSame([$second], array_column($todo['photos'], 'photoFingerprint'));
        $all = self::puzzleOf(self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?limit=1000'), self::PUZZLE);
        self::assertIsArray($all['photos']);
        self::assertIsArray($all['photos'][0]);
        self::assertIsArray($all['photos'][0]['analysis']);
        self::assertSame(self::VERSION, $all['photos'][0]['analysis']['analyzerVersion']);

        // Another version is stale again; `missing` ignores versions
        $stale = self::puzzleOf(self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?status=stale&version=2.0.0&limit=1000'), self::PUZZLE);
        self::assertIsArray($stale['photos']);
        self::assertCount(2, $stale['photos']);
        $missing = self::puzzleOf(self::callInternalApi($browser, 'GET', '/internal-api/finished-photos?status=missing&limit=1000'), self::PUZZLE);
        self::assertIsArray($missing['photos']);
        self::assertSame([$second], array_column($missing['photos'], 'photoFingerprint'));

        // Read back: the payload, current, never a player
        $read = self::callInternalApi($browser, 'GET', '/internal-api/finished-photo-analyses?onlyCurrent=1');
        self::assertResponseIsSuccessful();
        self::assertIsArray($read['items']);
        self::assertCount(1, $read['items']);
        $row = $read['items'][0];
        self::assertIsArray($row);
        self::assertSame(self::PUZZLE, $row['puzzleId']);
        self::assertSame($first, $row['photoFingerprint']);
        self::assertSame(['quality' => 'better'], $row['result']);
        self::assertTrue($row['current']);
        self::assertStringNotContainsString($this->photos[0]['player'], (string) $browser->getResponse()->getContent());

        // A new catalogue picture: stale everywhere, still readable without onlyCurrent
        $this->database()->executeStatement('UPDATE puzzle SET image = ? WHERE id = ?', ['box-500-01-new.jpg', self::PUZZLE]);
        $read = self::callInternalApi($browser, 'GET', '/internal-api/finished-photo-analyses');
        self::assertIsArray($read['items']);
        self::assertIsArray($read['items'][0]);
        self::assertFalse($read['items'][0]['current']);
        self::assertSame([], self::callInternalApi($browser, 'GET', '/internal-api/finished-photo-analyses?onlyCurrent=1')['items']);
    }

    public function testThePruneDeletesWhatWasMeasuredFromAPhotoThatIsGone(): void
    {
        $browser = self::createClient();
        $this->givePhotos();
        self::callInternalApi($browser, 'POST', '/internal-api/finished-photo-analyses', ['items' => [
            self::item(PuzzleImageFingerprint::of($this->photos[0]['path'])),
            self::item(PuzzleImageFingerprint::of($this->photos[1]['path'])),
        ]]);
        self::assertSame(2, $this->database()->fetchOne('SELECT COUNT(*) FROM finished_photo_analysis'));

        self::assertSame(0, $this->prune());

        // The player replaced the first photo; the second player went private
        $this->setPhoto($this->photos[0]['id'], 'players/x/replaced.jpg');
        $this->database()->executeStatement('UPDATE player SET is_private = true WHERE id = ?', [$this->photos[1]['player']]);

        self::assertSame(2, $this->prune());
        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM finished_photo_analysis'));
    }

    public function testTheCatalogueEndpointsRefuseTheFinishedPhotoAnalyzer(): void
    {
        $browser = self::createClient();
        $this->givePhotos();

        self::callInternalApi($browser, 'PUT', '/internal-api/puzzles/' . self::PUZZLE . '/image-analysis/finished_photo', [
            'analyzerVersion' => self::VERSION,
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGE),
            'result' => ['x' => 1],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::callInternalApi($browser, 'GET', '/internal-api/puzzle-images?analyzer=finished_photo&status=stale');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Three results of the puzzle by three different players get a photo; the third player is private.
     */
    private function givePhotos(): void
    {
        $database = $this->database();
        $database->executeStatement('UPDATE puzzle SET image = ? WHERE id = ?', [self::IMAGE, self::PUZZLE]);
        $rows = $database->fetchAllAssociative(
            'SELECT DISTINCT ON (pst.player_id) pst.id, pst.player_id FROM puzzle_solving_time pst WHERE pst.puzzle_id = ? ORDER BY pst.player_id, pst.id',
            [self::PUZZLE],
        );
        self::assertGreaterThanOrEqual(3, count($rows), 'the fixture puzzle needs results of three players');
        usort($rows, static fn (array $a, array $b): int => strcmp(is_string($a['id']) ? $a['id'] : '', is_string($b['id']) ? $b['id'] : ''));
        $this->photos = [];

        foreach (array_slice($rows, 0, 3) as $index => $row) {
            $id = $row['id'];
            $player = $row['player_id'];
            assert(is_string($id) && is_string($player));
            $path = sprintf('players/%s/%s-%d.jpg', $player, $id, 1_700_000_000 + $index);
            $this->setPhoto($id, $path);
            $this->photos[] = ['id' => $id, 'player' => $player, 'path' => $path];
        }

        $database->executeStatement('UPDATE player SET is_private = false WHERE id IN (?, ?)', [$this->photos[0]['player'], $this->photos[1]['player']]);
        $database->executeStatement('UPDATE player SET is_private = true WHERE id = ?', [$this->photos[2]['player']]);
    }

    private function setPhoto(string $solvingTimeId, string $path): void
    {
        $this->database()->executeStatement('UPDATE puzzle_solving_time SET finished_puzzle_photo = ? WHERE id = ?', [$path, $solvingTimeId]);
    }

    private function prune(): int
    {
        $handled = self::getContainer()->get(MessageBusInterface::class)->dispatch(new PruneFinishedPhotoAnalyses())->last(HandledStamp::class);
        assert($handled instanceof HandledStamp);
        $deleted = $handled->getResult();
        assert(is_int($deleted));

        return $deleted;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function item(
        string $photoFingerprint,
        null|string $imageFingerprint = null,
        string $analyzer = 'finished_photo',
        string $puzzleId = self::PUZZLE,
        array $result = ['locate' => ['method' => 'register']],
    ): array {
        return [
            'puzzleId' => $puzzleId,
            'analyzer' => $analyzer,
            'analyzerVersion' => self::VERSION,
            'photoFingerprint' => $photoFingerprint,
            'imageFingerprint' => $imageFingerprint ?? PuzzleImageFingerprint::of(self::IMAGE),
            'analyzedAt' => '2026-10-27T10:00:00Z',
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private static function puzzleOf(array $page, string $puzzleId): array
    {
        self::assertIsArray($page['puzzles']);

        foreach ($page['puzzles'] as $puzzle) {
            self::assertIsArray($puzzle);

            if ($puzzle['puzzleId'] === $puzzleId) {
                /** @var array<string, mixed> $puzzle */
                return $puzzle;
            }
        }

        self::fail("Puzzle $puzzleId is not listed");
    }

    private static function photoUri(string $puzzleId, string $photoFingerprint): string
    {
        return sprintf('/internal-api/puzzles/%s/finished-photos/%s', $puzzleId, $photoFingerprint);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
