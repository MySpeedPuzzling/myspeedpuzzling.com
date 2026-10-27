<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "Puzzle difficulty labels and estimates": the image pipeline's training labels
 * (as-of, aggregates only, secret puzzles never), its estimates (a batch with an outcome per item, the current value
 * per puzzle) and its model record.
 */
final class PuzzleDifficultyEstimatesInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string UNKNOWN_PUZZLE = '018d0003-0000-0000-0000-0000000009ff';

    private const array IMAGES = [
        PuzzleFixture::PUZZLE_500_01 => 'box-500-01-018d0003-dddd0001.jpg',
        PuzzleFixture::PUZZLE_500_02 => 'box-500-02-018d0003-dddd0002.jpg',
        PuzzleFixture::PUZZLE_500_03 => 'box-500-03-018d0003-dddd0003.jpg',
        PuzzleFixture::PUZZLE_HIDDEN_IMAGE => 'fedcba9876543210fedcba9876543210.jpg',
    ];

    private const array LABEL_FIELDS = [
        'addedAt',
        'imageFingerprint',
        'indexCount',
        'labelSource',
        'logDifficulty',
        'manufacturerId',
        'meanLogIndex',
        'piecesCount',
        'puzzleId',
    ];

    public function testEveryEndpointNeedsTheToken(): void
    {
        $browser = self::createClient();

        foreach (
            [
                ['GET', '/internal-api/puzzle-difficulty-labels'],
                ['PUT', '/internal-api/puzzle-difficulty-estimates'],
                ['POST', '/internal-api/puzzle-difficulty-estimates/model'],
                ['GET', '/internal-api/puzzle-difficulty-estimates/model'],
            ] as [$method, $uri]
        ) {
            self::callInternalApi($browser, $method, $uri, ['items' => []], token: null);
            self::assertResponseStatusCodeSame(401, "$method $uri");
        }
    }

    public function testLabelsAreTheAsOfDifficultyOfEveryMeasuredPuzzleAndNothingOfAnyPlayer(): void
    {
        $browser = self::createClient();
        $this->giveImages();
        $database = $this->database();
        $database->executeStatement('UPDATE puzzle SET image = NULL WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_02]);
        $database->executeStatement('DELETE FROM puzzle_difficulty');

        // 7 indices: the batch keeps their median - the label is its ln
        $this->difficulty(PuzzleFixture::PUZZLE_500_01, sampleSize: 7, sumLogIndices: 7 * log(1.1), median: 1.2);
        // 3 indices: no median stored - the mean of the ln indices
        $this->difficulty(PuzzleFixture::PUZZLE_500_02, sampleSize: 3, sumLogIndices: -0.3, median: null);
        // No index, and a row the batch has not computed with as-of indices yet: no label
        $this->difficulty(PuzzleFixture::PUZZLE_500_03, sampleSize: 0, sumLogIndices: 0.0, median: null);
        $this->difficulty(PuzzleFixture::PUZZLE_500_04, sampleSize: 6, sumLogIndices: null, median: 1.0);
        // Secret puzzles and pictures never become a training row
        $this->difficulty(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, sampleSize: 9, sumLogIndices: 0.9, median: 1.1);
        $this->difficulty(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, sampleSize: 9, sumLogIndices: 0.9, median: 1.1);
        $this->difficulty(CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, sampleSize: 9, sumLogIndices: 0.9, median: 1.1);

        $labels = [];
        $after = null;
        $pages = 0;

        do {
            $page = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-labels?limit=1' . ($after !== null ? '&after=' . $after : ''));
            self::assertResponseIsSuccessful();
            self::assertIsArray($page['labels']);
            self::assertLessThanOrEqual(1, count($page['labels']));

            foreach ($page['labels'] as $label) {
                self::assertIsArray($label);
                self::assertIsString($label['puzzleId']);
                $labels[$label['puzzleId']] = $label;
            }

            $after = $page['nextCursor'];
            self::assertTrue($after === null || is_string($after));
            $pages++;
        } while ($after !== null && $pages < 20);

        self::assertNull($after);
        self::assertSame([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02], array_keys($labels), 'in puzzle id order, only measured, shown puzzles');

        foreach ($labels as $label) {
            $fields = array_keys($label);
            sort($fields);
            self::assertSame(self::LABEL_FIELDS, $fields, 'aggregates of the puzzle only - no player id, no time, no index');
        }

        $median = $labels[PuzzleFixture::PUZZLE_500_01];
        self::assertEqualsWithDelta(log(1.2), $median['logDifficulty'], 0.000001);
        self::assertSame('median', $median['labelSource']);
        self::assertEqualsWithDelta(log(1.1), $median['meanLogIndex'], 0.000001);
        self::assertSame(7, $median['indexCount']);
        self::assertSame(500, $median['piecesCount']);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_01]), $median['imageFingerprint']);

        $puzzle = $database->fetchAssociative('SELECT manufacturer_id, added_at FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);
        self::assertIsArray($puzzle);
        self::assertSame($puzzle['manufacturer_id'], $median['manufacturerId']);
        self::assertIsString($puzzle['added_at']);
        self::assertIsString($median['addedAt']);
        self::assertSame((new \DateTimeImmutable($puzzle['added_at']))->getTimestamp(), (new \DateTimeImmutable($median['addedAt']))->getTimestamp());

        $meanLog = $labels[PuzzleFixture::PUZZLE_500_02];
        self::assertEqualsWithDelta(-0.1, $meanLog['logDifficulty'], 0.000001);
        self::assertSame('mean_log', $meanLog['labelSource']);
        self::assertEqualsWithDelta(-0.1, $meanLog['meanLogIndex'], 0.000001);
        self::assertSame(3, $meanLog['indexCount']);
        self::assertNull($meanLog['imageFingerprint'], 'no picture - nothing for the pipeline to join, still a label');

        $whole = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-labels');
        self::assertResponseIsSuccessful();
        self::assertIsArray($whole['labels']);
        self::assertCount(2, $whole['labels']);
        self::assertNull($whole['nextCursor']);
    }

    public function testRefusesInvalidLabelParameters(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-labels?limit=5001&after=nope&player=1');
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertSame(['after', 'limit', 'player'], self::sortedKeys($answer['errors']));
    }

    public function testABatchOfEstimatesTellsWhatBecameOfEachItem(): void
    {
        $browser = self::createClient();
        $this->giveImages();
        $this->database()->executeStatement('UPDATE puzzle SET image = NULL WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_04]);

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [
            self::estimate(PuzzleFixture::PUZZLE_500_01),
            ['imageFingerprint' => 'an-older-picture'] + self::estimate(PuzzleFixture::PUZZLE_500_02),
            self::estimate(PuzzleFixture::PUZZLE_HIDDEN_IMAGE),
            ['imageFingerprint' => 'whatever'] + self::estimate(PuzzleFixture::PUZZLE_500_01, puzzleId: CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN),
            self::estimate(PuzzleFixture::PUZZLE_500_01, puzzleId: self::UNKNOWN_PUZZLE),
            ['logP10' => 0.5, 'player' => 'x'] + self::estimate(PuzzleFixture::PUZZLE_500_03),
            ['imageFingerprint' => 'any'] + self::estimate(PuzzleFixture::PUZZLE_500_01, puzzleId: PuzzleFixture::PUZZLE_500_04),
        ]]);
        self::assertResponseIsSuccessful();

        self::assertSame(['stored' => 1, 'stale_fingerprint' => 2, 'hidden' => 2, 'unknown_puzzle' => 1, 'invalid' => 1], $answer['counts']);
        self::assertIsArray($answer['items']);
        self::assertSame(
            ['stored', 'stale_fingerprint', 'hidden', 'hidden', 'unknown_puzzle', 'invalid', 'stale_fingerprint'],
            array_map(static fn (mixed $item): mixed => is_array($item) ? $item['status'] : null, $answer['items']),
        );
        self::assertIsArray($answer['items'][1]);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_02]), $answer['items'][1]['currentFingerprint']);
        self::assertIsArray($answer['items'][5]);
        self::assertSame(5, $answer['items'][5]['index']);
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $answer['items'][5]['puzzleId']);
        self::assertIsArray($answer['items'][5]['errors']);
        self::assertSame(['logP10', 'player'], self::sortedKeys($answer['items'][5]['errors']));
        self::assertIsArray($answer['items'][6]);
        self::assertArrayNotHasKey('currentFingerprint', $answer['items'][6], 'a puzzle without a picture');

        $rows = $this->database()->fetchAllAssociative('SELECT * FROM puzzle_difficulty_estimate');
        self::assertCount(1, $rows);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $rows[0]['puzzle_id']);
        self::assertSame('ridge-siglip2-dinov2', $rows[0]['model']);
        self::assertSame('1.0.0', $rows[0]['model_version']);
        self::assertEqualsWithDelta(0.12, $rows[0]['log_difficulty'], 0.0000001);
        self::assertEqualsWithDelta(-0.15, $rows[0]['log_p10'], 0.0000001);
        self::assertEqualsWithDelta(0.4, $rows[0]['log_p90'], 0.0000001);
        self::assertTrue($rows[0]['out_of_fold']);
        self::assertSame(4979, $rows[0]['training_size']);
        self::assertSame('2026-10-19 03:00:00', $rows[0]['computed_at']);
    }

    public function testStoringIsAnIdempotentUpsertOfTheCurrentValue(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $batch = ['items' => [self::estimate(PuzzleFixture::PUZZLE_500_01), self::estimate(PuzzleFixture::PUZZLE_500_02)]];

        foreach ([1, 2] as $run) {
            $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', $batch);
            self::assertResponseIsSuccessful();
            self::assertSame(['stored' => 2, 'stale_fingerprint' => 0, 'hidden' => 0, 'unknown_puzzle' => 0, 'invalid' => 0], $answer['counts'], "run $run");
        }

        self::assertSame(2, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'));

        // A newer model replaces the value; the same puzzle twice in one batch - the later item wins
        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [
            ['modelVersion' => '1.1.0', 'logDifficulty' => 0.3, 'logP90' => 0.5] + self::estimate(PuzzleFixture::PUZZLE_500_01),
            ['modelVersion' => '1.1.0', 'logDifficulty' => -0.2, 'logP10' => -0.4, 'outOfFold' => false, 'computedAt' => null] + self::estimate(PuzzleFixture::PUZZLE_500_01),
        ]]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($answer['counts']);
        self::assertSame(2, $answer['counts']['stored']);

        $row = $this->database()->fetchAssociative('SELECT * FROM puzzle_difficulty_estimate WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);
        self::assertIsArray($row);
        self::assertSame('1.1.0', $row['model_version']);
        self::assertEqualsWithDelta(-0.2, $row['log_difficulty'], 0.0000001);
        self::assertFalse($row['out_of_fold']);
        self::assertNotSame('2026-10-19 03:00:00', $row['computed_at'], 'no computedAt = the moment of the push');
        self::assertSame(2, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'));
    }

    public function testABatchMustBeAListOfAtMost500(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => array_fill(0, 501, self::estimate(PuzzleFixture::PUZZLE_500_01))]);
        self::assertResponseStatusCodeSame(422);
        self::assertIsArray($answer['errors']);
        self::assertArrayHasKey('items', $answer['errors']);

        self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => []]);
        self::assertResponseStatusCodeSame(422);

        self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['estimates' => [self::estimate(PuzzleFixture::PUZZLE_500_01)]]);
        self::assertResponseStatusCodeSame(422);

        $full = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => array_fill(0, 500, self::estimate(PuzzleFixture::PUZZLE_500_01))]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($full['counts']);
        self::assertSame(500, $full['counts']['stored']);
        self::assertSame(1, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'));
    }

    public function testInvalidValuesAreNamedField(): void
    {
        $browser = self::createClient();
        $this->giveImages();

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [
            [
                'puzzleId' => 'nope',
                'model' => '',
                'modelVersion' => str_repeat('v', 51),
                'logDifficulty' => 9,
                'logP10' => '0.1',
                'logP90' => -0.5,
                'outOfFold' => 'yes',
                'trainingSize' => 0,
                'imageFingerprint' => "ab\0c",
                'computedAt' => '2026-02-30T10:00:00Z',
            ],
            ['logP90' => 0.0] + self::estimate(PuzzleFixture::PUZZLE_500_01),
            'not an object',
            ['logP10' => -1.0, 'logP90' => 0.6] + self::estimate(PuzzleFixture::PUZZLE_500_01),
        ]]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($answer['counts']);
        self::assertSame(4, $answer['counts']['invalid']);
        self::assertIsArray($answer['items']);
        self::assertIsArray($answer['items'][0]);
        self::assertIsArray($answer['items'][0]['errors']);
        self::assertSame(
            ['computedAt', 'imageFingerprint', 'logDifficulty', 'logP10', 'model', 'modelVersion', 'outOfFold', 'puzzleId', 'trainingSize'],
            self::sortedKeys($answer['items'][0]['errors']),
        );
        self::assertIsArray($answer['items'][1]);
        self::assertIsArray($answer['items'][1]['errors']);
        self::assertSame(['logP90'], self::sortedKeys($answer['items'][1]['errors']), 'logP90 below the estimate');
        self::assertIsArray($answer['items'][2]);
        self::assertIsArray($answer['items'][2]['errors']);
        self::assertSame(['item'], self::sortedKeys($answer['items'][2]['errors']));
        self::assertIsArray($answer['items'][3]);
        self::assertIsArray($answer['items'][3]['errors']);
        self::assertSame(['logP90'], self::sortedKeys($answer['items'][3]['errors']), 'An interval wider than 1.5');
        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'));
    }

    public function testThe120ValuesAreStoredReadBackAndClearedByAPushWithoutThem(): void
    {
        $browser = self::createClient();
        $this->giveImages();
        $factors = [0.5, -1.25, 2.0, 0.0, 1, -3, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, -99.5];

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [
            self::estimate(PuzzleFixture::PUZZLE_500_01) + [
                'logP25' => 0.0,
                'logP75' => 0.25,
                'pixelsPerPiece' => 41.37,
                'cropTrust' => 0.83,
                'pictureFactors' => $factors,
                'pictureFactorsVersion' => '1.0.0',
            ],
            self::estimate(PuzzleFixture::PUZZLE_500_02) + ['logP25' => null, 'logP75' => null, 'cropTrust' => null, 'pictureFactors' => null],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($answer['counts']);
        self::assertSame(2, $answer['counts']['stored']);

        $estimates = self::getContainer()->get(GetPuzzleDifficultyEstimates::class)->forPuzzles([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02]);
        $first = $estimates[PuzzleFixture::PUZZLE_500_01];
        self::assertSame(0.0, $first->logP25);
        self::assertSame(0.25, $first->logP75);
        self::assertSame(41.37, $first->pixelsPerPiece);
        self::assertSame(0.83, $first->cropTrust);
        self::assertSame(array_map(floatval(...), $factors), $first->pictureFactors);
        self::assertSame('1.0.0', $first->pictureFactorsVersion);
        $second = $estimates[PuzzleFixture::PUZZLE_500_02];
        self::assertNull($second->logP25);
        self::assertNull($second->pixelsPerPiece);
        self::assertNull($second->pictureFactors);
        self::assertNull($second->pictureFactorsVersion);

        // A push replaces the row whole: an older client (or another model) without the values leaves them empty
        self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [self::estimate(PuzzleFixture::PUZZLE_500_01)]]);
        self::assertResponseIsSuccessful();
        $row = $this->database()->fetchAssociative('SELECT log_p25, log_p75, pixels_per_piece, crop_trust, picture_factors, picture_factors_version FROM puzzle_difficulty_estimate WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);
        self::assertSame(['log_p25' => null, 'log_p75' => null, 'pixels_per_piece' => null, 'crop_trust' => null, 'picture_factors' => null, 'picture_factors_version' => null], $row);
    }

    public function testInvalid120ValuesAreNamedField(): void
    {
        $browser = self::createClient();
        $this->giveImages();
        $sixteen = array_fill(0, 16, 0.5);

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/puzzle-difficulty-estimates', ['items' => [
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['logP25' => 0.0],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['logP25' => 0.2, 'logP75' => 0.5],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['pixelsPerPiece' => 0, 'cropTrust' => 1.5],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['pictureFactors' => array_fill(0, 15, 0.5), 'pictureFactorsVersion' => '1.0.0'],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['pictureFactors' => $sixteen],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['pictureFactorsVersion' => '1.0.0'],
            self::estimate(PuzzleFixture::PUZZLE_500_01) + ['pictureFactors' => [...array_fill(0, 15, 0.5), 'x'], 'pictureFactorsVersion' => '1.0.0'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($answer['counts']);
        self::assertSame(7, $answer['counts']['invalid']);
        self::assertIsArray($answer['items']);

        $expected = [
            ['logP75'],
            ['logP25', 'logP75'],
            ['cropTrust', 'pixelsPerPiece'],
            ['pictureFactors'],
            ['pictureFactorsVersion'],
            ['pictureFactorsVersion'],
            ['pictureFactors'],
        ];

        foreach ($expected as $index => $fields) {
            $item = $answer['items'][$index];
            self::assertIsArray($item);
            self::assertIsArray($item['errors']);
            self::assertSame($fields, self::sortedKeys($item['errors']), "item $index");
        }

        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'));
    }

    public function testTheModelRecordIsTheLatestRunWithItsHistoryKept(): void
    {
        $browser = self::createClient();

        $none = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-estimates/model');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('current', $none);
        self::assertNull($none['current']);

        $evaluation = [
            'overall' => ['spearman' => 0.72, 'mae' => 0.11, 'tierExact' => 0.58, 'tierWithinOne' => 0.9, 'window50Hit' => 0.51, 'window80Hit' => 0.8, 'count' => 412],
            'groups' => [['sizeGroup' => '500', 'imageKind' => 'flat', 'spearman' => 0.74, 'count' => 300]],
            'holdouts' => ['brands' => ['spearman' => 0.7], 'newerFromOlder' => ['spearman' => 0.74]],
        ];

        $first = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-difficulty-estimates/model', [
            'model' => 'ridge-siglip2-dinov2',
            'modelVersion' => '1.0.0',
            'trainingSize' => 4979,
            'holdoutSpearman' => 0.74,
            'evaluation' => $evaluation,
            'createdAt' => '2026-10-19T03:00:00Z',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($first['id']);
        self::assertSame('ridge-siglip2-dinov2', $first['model']);
        self::assertSame('1.0.0', $first['modelVersion']);
        self::assertSame(4979, $first['trainingSize']);
        self::assertSame(0.74, $first['holdoutSpearman']);
        self::assertEquals($evaluation, $first['evaluation']);
        self::assertSame('2026-10-19T03:00:00+00:00', $first['createdAt']);
        self::assertIsString($first['receivedAt']);

        $current = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-estimates/model');
        self::assertIsArray($current['current']);
        self::assertSame($first['id'], $current['current']['id']);

        $second = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-difficulty-estimates/model', [
            'model' => 'ridge-siglip2-dinov2',
            'modelVersion' => '1.1.0',
            'trainingSize' => 5100,
            'holdoutSpearman' => 0.75,
            'evaluation' => ['overall' => ['spearman' => 0.75]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $another = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-difficulty-estimates/model', [
            'model' => 'ridge-pieces-only',
            'modelVersion' => '0.1.0',
            'trainingSize' => 5100,
            'holdoutSpearman' => 0.3,
            'evaluation' => ['overall' => ['spearman' => 0.3]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $latest = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-estimates/model');
        self::assertIsArray($latest['current']);
        self::assertSame($another['id'], $latest['current']['id'], 'current = the latest run of any model');

        $ofModel = self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-estimates/model?model=ridge-siglip2-dinov2');
        self::assertIsArray($ofModel['current']);
        self::assertSame($second['id'], $ofModel['current']['id']);
        self::assertSame('1.1.0', $ofModel['current']['modelVersion']);

        self::assertSame(3, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate_model'), 'history kept');
    }

    public function testAnInvalidModelRecordIsRefused(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-difficulty-estimates/model', [
            'model' => 'ridge',
            'trainingSize' => -1,
            'holdoutSpearman' => 1.5,
            'evaluation' => [],
            'spearman' => 0.7,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertIsArray($answer['errors']);
        self::assertSame(['evaluation', 'holdoutSpearman', 'modelVersion', 'spearman', 'trainingSize'], self::sortedKeys($answer['errors']));

        self::callInternalApi($browser, 'GET', '/internal-api/puzzle-difficulty-estimates/model?model=&version=1');
        self::assertResponseStatusCodeSame(400);

        self::assertSame(0, $this->database()->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate_model'));
    }

    private function giveImages(): void
    {
        foreach (self::IMAGES as $puzzleId => $image) {
            $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        }
    }

    private function difficulty(string $puzzleId, int $sampleSize, null|float $sumLogIndices, null|float $median): void
    {
        $now = TestClock::SQL_NOW;
        $this->database()->executeStatement(
            <<<SQL
INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, confidence, sample_size, sum_log_indices, computed_at)
VALUES (:puzzleId, :median, 'high', :sampleSize, :sumLogIndices, {$now})
SQL,
            ['puzzleId' => $puzzleId, 'median' => $median, 'sampleSize' => $sampleSize, 'sumLogIndices' => $sumLogIndices],
        );
    }

    /**
     * An estimate of the puzzle's current picture - `$puzzleId` sends it for another puzzle
     *
     * @return array<string, mixed>
     */
    private static function estimate(string $imageOf, null|string $puzzleId = null): array
    {
        return [
            'puzzleId' => $puzzleId ?? $imageOf,
            'model' => 'ridge-siglip2-dinov2',
            'modelVersion' => '1.0.0',
            'logDifficulty' => 0.12,
            'logP10' => -0.15,
            'logP90' => 0.4,
            'outOfFold' => true,
            'trainingSize' => 4979,
            'imageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[$imageOf]),
            'computedAt' => '2026-10-19T03:00:00Z',
        ];
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
