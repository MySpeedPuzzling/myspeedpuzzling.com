<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class PuzzleImageNamerTest extends TestCase
{
    private const string PUZZLE_ID = '019a116f-2a3b-7c4d-8e5f-60718293a4b5';

    public function testTheNameIsReadableAndEndsWithARandomPart(): void
    {
        $name = $this->namer()->generateFilename('Ravensburger', 'Kouzelné ráno', 1000, self::PUZZLE_ID, 'jpg');

        self::assertMatchesRegularExpression('/^ravensburger-kouzelne-rano-1000-019a116f-[0-9a-f]{8}\.jpg$/', $name);
        self::assertFalse(PuzzleImageNamer::isSecretFilename($name));
    }

    /**
     * The image caches key on the name - a second picture under the first one's name would keep its thumbnails.
     * The old suffix was a uuid7 prefix: the same for about a minute.
     */
    public function testEveryNameForTheSamePuzzleIsNew(): void
    {
        $namer = $this->namer();
        $names = [];

        for ($i = 0; $i < 50; $i++) {
            $names[] = $namer->generateFilename('Ravensburger', 'Puzzle 1', 500, self::PUZZLE_ID, 'jpg');
        }

        self::assertCount(50, array_unique($names));
    }

    private function namer(): PuzzleImageNamer
    {
        return new PuzzleImageNamer(new AsciiSlugger());
    }
}
