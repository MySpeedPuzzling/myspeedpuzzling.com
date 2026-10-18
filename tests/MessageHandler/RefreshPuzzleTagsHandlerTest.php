<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CorrectPuzzleTag;
use SpeedPuzzling\Web\Message\RefreshPuzzleTags;
use SpeedPuzzling\Web\Message\RemovePuzzleTagCorrection;
use SpeedPuzzling\Web\Query\GetPuzzleTags;
use SpeedPuzzling\Web\Results\PuzzleTagWriteOutcome;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;
use SpeedPuzzling\Web\Value\PuzzleTagGroup;
use SpeedPuzzling\Web\Value\PuzzleTagSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/insights/tags.md: puzzle_tag = the `tags` analysis of the puzzle's current picture ± the corrections,
 * written only for puzzles whose input changed, never for a secret puzzle.
 */
final class RefreshPuzzleTagsHandlerTest extends KernelTestCase
{
    private const string IMAGE_A = 'box-500-01-018d0003-tags-a.jpg';

    private const string IMAGE_B = 'box-500-02-018d0003-tags-b.jpg';

    private const string IMAGE_C = 'box-500-03-018d0003-tags-c.jpg';

    private const string IMAGE_HIDDEN = 'box-hidden-018d0003-tags.jpg';

    protected function setUp(): void
    {
        self::bootKernel();

        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE_A, 'id' => PuzzleFixture::PUZZLE_500_01]);
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE_B, 'id' => PuzzleFixture::PUZZLE_500_02]);
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE_C, 'id' => PuzzleFixture::PUZZLE_500_03]);
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE_HIDDEN, 'id' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE]);
    }

    public function testWritesTheAssignedTagsOfTheCurrentPicture(): void
    {
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, [
            'trait.large_uniform_areas' => ['s' => 0.91, 'src' => ['traits']],
            'style.photo' => ['s' => 0.6, 'src' => ['describe'], 'uncertain' => true],
            'theme.pets.cats' => ['s' => 0.93, 'src' => ['zeroshot']],
            'Not A Key' => ['s' => 1.0],
        ]);
        // 500_02's analysis describes a picture it no longer has
        $this->storeTags(PuzzleFixture::PUZZLE_500_02, 'its-previous-picture.jpg', ['trait.dark' => ['s' => 0.8]]);

        $outcome = $this->refresh();
        self::assertSame(3, $outcome->written);

        $tags = $this->tagsOf(PuzzleFixture::PUZZLE_500_01);
        self::assertSame(['style.photo', 'theme.pets.cats', 'trait.large_uniform_areas'], array_keys($tags));
        self::assertSame(0.91, $tags['trait.large_uniform_areas']['score']);
        self::assertTrue($tags['style.photo']['uncertain']);
        self::assertSame(PuzzleTagSource::Analysis, $tags['style.photo']['source']);
        self::assertSame('1.0.0+rules@tax1.0.0', $tags['style.photo']['analysisVersion']);
        self::assertSame('1.0.0', $tags['style.photo']['taxonomyVersion']);

        self::assertSame([], $this->tagsOf(PuzzleFixture::PUZZLE_500_02), 'only an analysis of the current picture counts');

        // The registry's group narrows a read to what Insights shows
        $group = self::getContainer()->get(GetPuzzleTags::class)->forPuzzles([PuzzleFixture::PUZZLE_500_01], PuzzleTagGroup::ImageTraits);
        self::assertSame(['style.photo', 'trait.large_uniform_areas'], array_map(static fn ($tag): string => $tag->tagKey, $group[PuzzleFixture::PUZZLE_500_01]));
    }

    public function testASecondRunChangesNothingAndANewPushOnlyItsPuzzle(): void
    {
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['trait.dark' => ['s' => 0.8]]);
        $this->storeTags(PuzzleFixture::PUZZLE_500_02, self::IMAGE_B, ['trait.text_heavy' => ['s' => 0.7]]);
        // An analysis without any tag: nothing to write, and no reason to come back to it
        $this->storeTags(PuzzleFixture::PUZZLE_500_03, self::IMAGE_C, []);

        $first = $this->refresh();
        self::assertSame(3, $first->puzzles);
        self::assertSame(2, $first->written);

        $second = $this->refresh();
        self::assertSame(0, $second->puzzles, 'idempotent - nothing changed');

        // The pipeline pushes a new analysis for 500_01: a tag goes, a tag comes
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['trait.busy_detail' => ['s' => 0.85]], version: '1.0.1+rules@tax1.0.0');
        $third = $this->refresh();
        self::assertSame(1, $third->puzzles);
        self::assertSame(1, $third->written);
        self::assertSame(1, $third->removed);
        self::assertSame(['trait.busy_detail'], array_keys($this->tagsOf(PuzzleFixture::PUZZLE_500_01)));
        self::assertSame(['trait.text_heavy'], array_keys($this->tagsOf(PuzzleFixture::PUZZLE_500_02)));

        // A new picture: its old analysis no longer counts, the tags go
        $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => 'box-500-02-new.jpg', 'id' => PuzzleFixture::PUZZLE_500_02]);
        self::assertSame(1, $this->refresh()->removed);
        self::assertSame([], $this->rawTagRows(PuzzleFixture::PUZZLE_500_02));
        self::assertSame(0, $this->refresh()->puzzles);
    }

    public function testCorrectionsWinAndSurviveReRuns(): void
    {
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, [
            'trait.dark' => ['s' => 0.8],
            'style.photo' => ['s' => 0.9],
        ]);
        $this->refresh();

        $this->correct(PuzzleFixture::PUZZLE_500_01, 'style.photo', PuzzleTagCorrectionVerdict::Remove);
        $this->correct(PuzzleFixture::PUZZLE_500_01, 'style.watercolour', PuzzleTagCorrectionVerdict::Add);
        $this->correct(PuzzleFixture::PUZZLE_500_01, 'trait.dark', PuzzleTagCorrectionVerdict::Add);
        // The daily run notices the corrections by itself
        self::assertSame(1, $this->refresh()->puzzles);

        $tags = $this->tagsOf(PuzzleFixture::PUZZLE_500_01);
        self::assertSame(['style.watercolour', 'trait.dark'], array_keys($tags));
        self::assertSame(PuzzleTagSource::Correction, $tags['style.watercolour']['source']);
        self::assertNull($tags['style.watercolour']['score']);
        // A confirmed tag keeps the analysis' score, and stays whatever the next analysis says
        self::assertSame(PuzzleTagSource::Correction, $tags['trait.dark']['source']);
        self::assertSame(0.8, $tags['trait.dark']['score']);

        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['style.photo' => ['s' => 0.95], 'trait.smooth_gradients' => ['s' => 0.5]], version: '1.0.1+rules@tax1.0.0');
        $this->refresh();
        self::assertSame(['style.watercolour', 'trait.dark', 'trait.smooth_gradients'], array_keys($this->tagsOf(PuzzleFixture::PUZZLE_500_01)));

        // Without its correction the key belongs to the analysis again
        $this->dispatch(new RemovePuzzleTagCorrection(PuzzleFixture::PUZZLE_500_01, 'style.photo'));
        $this->dispatch(new RemovePuzzleTagCorrection(PuzzleFixture::PUZZLE_500_01, 'trait.dark'));
        $this->refresh([PuzzleFixture::PUZZLE_500_01]);
        self::assertSame(['style.photo', 'style.watercolour', 'trait.smooth_gradients'], array_keys($this->tagsOf(PuzzleFixture::PUZZLE_500_01)));
    }

    public function testASecretPuzzleGetsNoTags(): void
    {
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, ['trait.dark' => ['s' => 0.8]]);
        // Stored before its picture was hidden
        $this->storeTags(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, self::IMAGE_HIDDEN, ['trait.dark' => ['s' => 0.8]]);

        $this->refresh();
        self::assertSame([], $this->rawTagRows(PuzzleFixture::PUZZLE_HIDDEN_IMAGE));

        // A puzzle turning secret loses its rows with the next run - and readers never show them meanwhile
        $this->database()->executeStatement("UPDATE puzzle SET hide_image_until = '2099-12-31' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_01]);
        self::assertSame([], self::getContainer()->get(GetPuzzleTags::class)->forPuzzles([PuzzleFixture::PUZZLE_500_01]));
        $this->refresh();
        self::assertSame([], $this->rawTagRows(PuzzleFixture::PUZZLE_500_01));

        // Revealed: the tags are back
        $this->database()->executeStatement('UPDATE puzzle SET hide_image_until = NULL WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);
        $this->refresh();
        self::assertSame(['trait.dark'], array_keys($this->tagsOf(PuzzleFixture::PUZZLE_500_01)));
    }

    public function testAnEmptyMapOrAJunkPayloadWritesNothing(): void
    {
        $this->storeTags(PuzzleFixture::PUZZLE_500_01, self::IMAGE_A, []);
        $this->storeTags(PuzzleFixture::PUZZLE_500_02, self::IMAGE_B, ['trait.dark' => ['s' => 0.8]]);
        $this->database()->executeStatement(
            "UPDATE puzzle_image_analysis SET result = '{\"assigned\": \"junk\"}' WHERE puzzle_id = :id AND analyzer = 'tags'",
            ['id' => PuzzleFixture::PUZZLE_500_02],
        );

        self::assertSame(0, $this->refresh()->written);
    }

    /**
     * @param array<string, array<string, mixed>> $assigned
     */
    private function storeTags(string $puzzleId, string $image, array $assigned, string $version = '1.0.0+rules@tax1.0.0'): void
    {
        $result = json_encode(['taxonomy' => '1.0.0', 'assigned' => $assigned, 'candidates' => null], JSON_THROW_ON_ERROR);

        $this->database()->executeStatement(
            <<<SQL
INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
VALUES (:id, :puzzleId, 'tags', :version, :fingerprint, CAST(:result AS jsonb), NULL, now())
ON CONFLICT (analyzer, puzzle_id) DO UPDATE SET analyzer_version = EXCLUDED.analyzer_version, image_fingerprint = EXCLUDED.image_fingerprint, result = EXCLUDED.result
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzleId' => $puzzleId,
                'version' => $version,
                'fingerprint' => PuzzleImageFingerprint::of($image),
                'result' => $result,
            ],
        );
    }

    private function correct(string $puzzleId, string $tagKey, PuzzleTagCorrectionVerdict $verdict): void
    {
        $this->dispatch(new CorrectPuzzleTag($puzzleId, $tagKey, $verdict, 'checked by eye', PlayerFixture::PLAYER_ADMIN));
    }

    /**
     * @param null|list<string> $puzzleIds
     */
    private function refresh(null|array $puzzleIds = null): PuzzleTagWriteOutcome
    {
        $outcome = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RefreshPuzzleTags($puzzleIds))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PuzzleTagWriteOutcome::class, $outcome);

        return $outcome;
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    /**
     * @return array<string, array{score: null|float, uncertain: bool, source: PuzzleTagSource, analysisVersion: null|string, taxonomyVersion: null|string}>
     */
    private function tagsOf(string $puzzleId): array
    {
        $tags = [];

        foreach (self::getContainer()->get(GetPuzzleTags::class)->ofPuzzle($puzzleId) as $tag) {
            $tags[$tag->tagKey] = [
                'score' => $tag->score,
                'uncertain' => $tag->uncertain,
                'source' => $tag->source,
                'analysisVersion' => $tag->analysisVersion,
                'taxonomyVersion' => $tag->taxonomyVersion,
            ];
        }

        return $tags;
    }

    /**
     * @return list<mixed>
     */
    private function rawTagRows(string $puzzleId): array
    {
        return $this->database()->fetchFirstColumn('SELECT tag_key FROM puzzle_tag WHERE puzzle_id = ?', [$puzzleId]);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
