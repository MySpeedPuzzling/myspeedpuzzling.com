<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleImageAnalysis;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;

final class PuzzleImageAnalysisInputTest extends TestCase
{
    private const string PUZZLE = '018d0003-0000-0000-0000-000000000001';

    public function testReadsAValidBody(): void
    {
        $input = PuzzleImageAnalysisInput::fromBody(strtoupper(self::PUZZLE), 'artwork', [
            'analyzerVersion' => ' crop-v3 ',
            'imageFingerprint' => '0123456789abcdef',
            'analyzedAt' => '2026-10-18T08:30:00+02:00',
            'result' => [
                'bbox' => ['x' => 0.1, 'y' => 0, 'w' => 0.9, 'h' => 1],
                'corners' => [[0.1, 0], ['x' => 1, 'y' => 0], [1, 1], [0.1, 1]],
                'confidence' => 0.93,
            ],
        ]);

        self::assertSame(self::PUZZLE, $input->puzzleId);
        self::assertSame(ImageAnalyzer::Artwork, $input->analyzer);
        self::assertSame('crop-v3', $input->analyzerVersion);
        self::assertSame('2026-10-18 06:30:00 UTC', $input->analyzedAt?->format('Y-m-d H:i:s T'));
    }

    public function testAnItemCarriesItsPuzzleAndAnalyzer(): void
    {
        $input = PuzzleImageAnalysisInput::fromItem([
            'puzzleId' => self::PUZZLE,
            'analyzer' => 'tags',
            'analyzerVersion' => 'tags-1',
            'imageFingerprint' => 'abc',
            'result' => ['tags' => ['cats', 'garden']],
        ]);

        self::assertSame(ImageAnalyzer::Tags, $input->analyzer);
        self::assertNull($input->analyzedAt);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $fieldsWithErrors
     */
    #[DataProvider('invalidBodies')]
    public function testCollectsEveryMistake(string $analyzer, array $body, array $fieldsWithErrors): void
    {
        try {
            PuzzleImageAnalysisInput::fromBody(self::PUZZLE, $analyzer, $body);
            self::fail('Expected the body to be refused');
        } catch (InvalidPuzzleImageAnalysis $exception) {
            self::assertSame(422, $exception->getStatusCode());
            $fields = array_keys($exception->errors);
            sort($fields);
            self::assertSame($fieldsWithErrors, $fields);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['analyzerVersion' => 'v1', 'imageFingerprint' => 'abc', 'result' => ['a' => 1]];

        yield 'unknown analyzer' => ['palette', $valid, ['analyzer']];
        yield 'nothing at all' => ['colors', [], ['analyzerVersion', 'imageFingerprint', 'result']];
        yield 'unknown field' => ['colors', $valid + ['resutl' => []], ['resutl']];
        yield 'version too long' => ['colors', ['analyzerVersion' => str_repeat('v', 51)] + $valid, ['analyzerVersion']];
        yield 'result a list' => ['colors', ['result' => [1, 2]] + $valid, ['result']];
        yield 'result empty' => ['colors', ['result' => []] + $valid, ['result']];
        yield 'result a string' => ['colors', ['result' => 'x'] + $valid, ['result']];
        yield 'result too big' => ['colors', ['result' => ['blob' => str_repeat('x', ImageAnalyzer::Colors->maxResultBytes())]] + $valid, ['result']];
        yield 'analyzedAt no date' => ['colors', ['analyzedAt' => 'yesterday'] + $valid, ['analyzedAt']];
        yield 'analyzedAt no real day' => ['colors', ['analyzedAt' => '2026-02-30T10:00:00Z'] + $valid, ['analyzedAt']];
        yield 'bbox outside the image' => ['artwork', ['result' => ['bbox' => ['x' => 0.5, 'y' => 0, 'w' => 0.6, 'h' => 1]]] + $valid, ['result.bbox']];
        yield 'bbox in pixels' => ['artwork', ['result' => ['bbox' => ['x' => 10, 'y' => 0, 'w' => 600, 'h' => 400]]] + $valid, ['result.bbox']];
        yield 'bbox without height' => ['artwork', ['result' => ['bbox' => ['x' => 0, 'y' => 0, 'w' => 1]]] + $valid, ['result.bbox']];
        yield 'bbox of no size' => ['artwork', ['result' => ['bbox' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 1]]] + $valid, ['result.bbox']];
        yield 'three corners' => ['artwork', ['result' => ['corners' => [[0, 0], [1, 0], [1, 1]]]] + $valid, ['result.corners']];
        yield 'a corner outside' => ['artwork', ['result' => ['corners' => [[0, 0], [1.5, 0], [1, 1], [0, 1]]]] + $valid, ['result.corners']];
        yield 'a corner of strings' => ['artwork', ['result' => ['corners' => [['0', '0'], [1, 0], [1, 1], [0, 1]]]] + $valid, ['result.corners']];
    }

    public function testOnlyArtworkBoxesAreChecked(): void
    {
        $input = PuzzleImageAnalysisInput::fromBody(self::PUZZLE, 'colors', [
            'analyzerVersion' => 'v1',
            'imageFingerprint' => 'abc',
            'result' => ['bbox' => ['x' => 100, 'y' => 200]],
        ]);

        self::assertSame(['bbox' => ['x' => 100, 'y' => 200]], $input->result);
    }

    public function testRoundingOfAModelIsNotOutsideTheImage(): void
    {
        $input = PuzzleImageAnalysisInput::fromBody(self::PUZZLE, 'artwork', [
            'analyzerVersion' => 'v1',
            'imageFingerprint' => 'abc',
            'result' => ['bbox' => ['x' => 0.3, 'y' => 0.1, 'w' => 0.7000000001, 'h' => 0.9], 'corners' => null],
        ]);

        self::assertSame(ImageAnalyzer::Artwork, $input->analyzer);
    }

    public function testAnItemThatIsNoObjectIsInvalid(): void
    {
        $this->expectException(InvalidPuzzleImageAnalysis::class);

        PuzzleImageAnalysisInput::fromItem('nope');
    }
}
