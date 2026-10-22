<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "Puzzle tags": reading a puzzle's tags, correcting one tag (it wins over the analysis
 * and is applied at once), removing the correction. Hidden pictures answer 404.
 */
final class PuzzleTagsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string IMAGE = 'box-500-01-018d0003-api-tags.jpg';

    public function testEveryEndpointNeedsTheToken(): void
    {
        $browser = self::createClient();

        foreach (
            [
                ['GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tags'],
                ['PUT', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/trait.dark'],
                ['DELETE', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/trait.dark'],
            ] as [$method, $uri]
        ) {
            self::callInternalApi($browser, $method, $uri, ['verdict' => 'add'], token: null);
            self::assertResponseStatusCodeSame(401, "$method $uri");
        }
    }

    public function testACorrectionIsAppliedAtOnceAndRemovedAgain(): void
    {
        $browser = self::createClient();
        $this->analysedPuzzle();

        $tags = self::callInternalApi($browser, 'GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tags');
        self::assertResponseIsSuccessful();
        self::assertSame([], $tags['tags'], 'not written before the daily run');
        self::assertSame('1.0.0', $tags['taxonomyVersion']);

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/style.photo', [
            'verdict' => 'remove',
            'note' => 'A painting, not a photo',
        ]);
        self::assertResponseIsSuccessful();

        // The correction rewrote the puzzle's tags: the analysis' tag without the removed one
        self::assertSame(['trait.dark'], array_column(self::listOf($answer['tags']), 'key'));
        $tag = self::listOf($answer['tags'])[0];
        self::assertSame('analysis', $tag['source']);
        self::assertSame('puzzle_tags.trait.dark', $tag['labelKey']);

        $corrections = self::listOf($answer['corrections']);
        self::assertCount(1, $corrections);
        self::assertSame('style.photo', $corrections[0]['key']);
        self::assertSame('remove', $corrections[0]['verdict']);
        self::assertSame('A painting, not a photo', $corrections[0]['note']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $corrections[0]['decidedById']);

        // A new word on the same key replaces the old one
        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/style.photo', ['verdict' => 'add']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::listOf($answer['corrections']));
        self::assertSame(['style.photo', 'trait.dark'], array_column(self::listOf($answer['tags']), 'key'));
        self::assertSame('correction', self::listOf($answer['tags'])[0]['source']);

        $answer = self::callInternalApi($browser, 'DELETE', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/style.photo');
        self::assertResponseIsSuccessful();
        self::assertSame([], $answer['corrections']);
        self::assertSame('analysis', self::listOf($answer['tags'])[0]['source']);

        // Removing what is not there is fine
        self::callInternalApi($browser, 'DELETE', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/style.photo');
        self::assertResponseIsSuccessful();
    }

    public function testRefusesWhatCannotBeACorrection(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/tag-corrections/';

        self::callInternalApi($browser, 'PUT', $uri . 'trait.dark', ['verdict' => 'maybe']);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'PUT', $uri . 'trait.dark', []);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'PUT', $uri . 'trait.dark', ['verdict' => 'add', 'note' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(400);

        // Well formed for the route, but no facet of the taxonomy
        $answer = self::callInternalApi($browser, 'PUT', $uri . 'colour.dark', ['verdict' => 'add']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', $answer);

        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM puzzle_tag_correction'));
    }

    public function testAHiddenPictureHasNoTagsToReadOrCorrect(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'GET', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE . '/tags');
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'PUT', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE . '/tag-corrections/trait.dark', ['verdict' => 'add']);
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'DELETE', '/internal-api/puzzles/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE . '/tag-corrections/trait.dark');
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'GET', '/internal-api/puzzles/018d0003-0000-0000-0000-0000000009ff/tags');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM puzzle_tag_correction'));
    }

    private function analysedPuzzle(): void
    {
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE, 'id' => PuzzleFixture::PUZZLE_500_01]);
        $now = TestClock::SQL_NOW;
        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
VALUES (:id, :puzzleId, 'tags', '1.0.0+rules@tax1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, {$now})
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzleId' => PuzzleFixture::PUZZLE_500_01,
                'fingerprint' => PuzzleImageFingerprint::of(self::IMAGE),
                'result' => json_encode(['taxonomy' => '1.0.0', 'assigned' => ['trait.dark' => ['s' => 0.8], 'style.photo' => ['s' => 0.9]]], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
